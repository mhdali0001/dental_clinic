<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// التحقق من وجود معرف العلاج
$treatment_id = $_GET['id'] ?? 0;
if (!$treatment_id) {
    header('Location: treatments.php');
    exit;
}

$doctor_id = $_SESSION['user_id'];

// جلب بيانات العلاج
try {
    $stmt = $pdo->prepare("
        SELECT t.*, p.name as patient_name, p.phone, p.age, p.gender, p.address,
               p.medical_history, p.allergies, p.blood_type, p.emergency_contact,
               a.appointment_time, a.appointment_date,
               d.full_name as doctor_name,
               COALESCE(SUM(pay.amount), 0) as total_paid
        FROM treatments t 
        JOIN patients p ON t.patient_id = p.id 
        LEFT JOIN appointments a ON t.appointment_id = a.id
        LEFT JOIN users d ON t.doctor_id = d.id
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE t.id = ? AND t.doctor_id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$treatment_id, $doctor_id]);
    $treatment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$treatment) {
        header('Location: treatments.php');
        exit;
    }
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $treatment = null;
}

// جلب تاريخ الدفعات
try {
    $stmt = $pdo->prepare("
        SELECT pay.*, u.full_name as created_by_name
        FROM payments pay
        LEFT JOIN users u ON pay.created_by = u.id
        WHERE pay.treatment_id = ?
        ORDER BY pay.payment_date DESC
    ");
    $stmt->execute([$treatment_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// جلب العلاجات السابقة للمريض
try {
    $stmt = $pdo->prepare("
        SELECT t.*, d.full_name as doctor_name
        FROM treatments t
        LEFT JOIN users d ON t.doctor_id = d.id
        WHERE t.patient_id = ? AND t.id != ?
        ORDER BY t.treatment_date DESC
        LIMIT 5
    ");
    $stmt->execute([$treatment['patient_id'], $treatment_id]);
    $previous_treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $previous_treatments = [];
}

// جلب مراحل العلاج إذا كانت موجودة
$treatment_stages = [];
if (!empty($treatment['treatment_stages'])) {
    $stages_json = $treatment['treatment_stages'];
    if (is_string($stages_json)) {
        $treatment_stages = json_decode($stages_json, true) ?: [];
    }
}

// حساب التقدم في المراحل
$stages_progress = [
    'total' => count($treatment_stages),
    'completed' => 0,
    'percentage' => 0
];

foreach ($treatment_stages as $stage) {
    if (isset($stage['completed']) && $stage['completed']) {
        $stages_progress['completed']++;
    }
}

if ($stages_progress['total'] > 0) {
    $stages_progress['percentage'] = round(($stages_progress['completed'] / $stages_progress['total']) * 100);
}

// حساب المبالغ
$remaining_balance = ($treatment['cost'] ?? 0) - $treatment['total_paid'];

// قائمة أنواع العلاجات
$treatment_types = [
    'cleaning' => 'تنظيف الأسنان',
    'filling' => 'حشو الأسنان',
    'extraction' => 'قلع الأسنان',
    'root_canal' => 'علاج عصب',
    'crown' => 'تاج الأسنان',
    'bridge' => 'جسر الأسنان',
    'implant' => 'زراعة الأسنان',
    'orthodontics' => 'تقويم الأسنان',
    'whitening' => 'تبييض الأسنان',
    'dentures' => 'طقم أسنان',
    'scaling' => 'تقليح الأسنان',
    'consultation' => 'استشارة',
    'emergency' => 'علاج طارئ',
    'surgery' => 'جراحة الفم',
    'periodontal' => 'علاج اللثة',
    'preventive' => 'علاج وقائي',
    'cosmetic' => 'علاج تجميلي',
    'other' => 'أخرى'
];

// الحصول على اسم نوع العلاج
$treatment_type_name = $treatment_types[$treatment['treatment_type']] ?? $treatment['treatment_type'];

// Header configuration
$pageTitle = 'تفاصيل العلاج';
$pageIcon = 'fas fa-file-medical-alt';
$pageSubtitle = 'معلومات مفصلة عن العلاج';
$currentPage = 'treatments';
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
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .medical-alert { background: linear-gradient(45deg, #fee2e2, #fef2f2); box-shadow: 0 4px 15px rgba(239, 68, 68, 0.1); }
        .treatment-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3); }
        .info-card {
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
        }
        .info-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            border-color: #d1d5db;
        }
        .stage-timeline { position: relative; }
        .stage-timeline::before { content: ''; position: absolute; right: 20px; top: 0; bottom: 0; width: 3px; background: linear-gradient(to bottom, #e5e7eb 0%, #d1d5db 100%); border-radius: 2px; }
        .stage-item { position: relative; padding-right: 55px; margin-bottom: 24px; }
        .stage-item::before {
            content: '';
            position: absolute;
            right: 8.5px;
            top: 20px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #f9fafb;
            border: 4px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .stage-item.completed::before {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .stage-item.in-progress::before {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-color: #f59e0b;
            animation: pulse 2s infinite;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        .progress-ring { transform: rotate(-90deg); }
        .progress-ring-fill { transition: stroke-dashoffset 0.3s ease; }

        /* Enhanced animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        /* Payment status styling */
        .payment-indicator {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tooth diagram styling */
        .tooth-item {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .tooth-item:hover {
            transform: scale(1.1);
        }
        .tooth-treated {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .stage-timeline::before { right: 15px; }
            .stage-item { padding-right: 45px; }
            .stage-item::before { right: 6.5px; width: 18px; height: 18px; }
        }

        /* Print enhancements */
        @media print {
            .info-card {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                margin-bottom: 20px !important;
            }
            .stage-timeline::before { background: #666 !important; }
            .stage-item.completed::before { background: #000 !important; border-color: #000 !important; }
            .stage-item.in-progress::before { background: #666 !important; border-color: #666 !important; }
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

        <?php if (!$treatment): ?>
            <div class="bg-white rounded-lg shadow-lg p-16 text-center">
                <i class="fas fa-file-times text-6xl text-gray-300 mb-6"></i>
                <h3 class="text-2xl font-bold text-gray-900 mb-4">العلاج غير موجود</h3>
                <p class="text-gray-600 mb-8">لم يتم العثور على العلاج المطلوب.</p>
                <a href="treatments.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                    العودة لقائمة العلاجات
                </a>
            </div>
        <?php else: ?>

<!-- Treatment Header Card - محدث -->
<div class="treatment-header text-white rounded-lg shadow-lg p-8 mb-8 fade-in">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center mb-6 lg:mb-0">
            <div class="bg-white bg-opacity-20 p-4 rounded-full">
                <i class="fas fa-tooth text-3xl text-white"></i>
            </div>
            <div class="mr-6">
                <h2 class="text-3xl font-bold"><?= htmlspecialchars($treatment_type_name) ?></h2>
                <p class="text-lg opacity-90 mt-1">
                    المريض: <?= htmlspecialchars($treatment['patient_name']) ?>
                </p>
                <p class="text-sm opacity-75 mt-1">
                    تاريخ العلاج: <?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?>
                    <?php if ($treatment['appointment_time']): ?>
                        - <?= date('H:i', strtotime($treatment['appointment_time'])) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <div class="flex flex-col sm:flex-row gap-3">
            <!-- زر الملف الشخصي -->
            <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>"
               class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                <i class="fas fa-user-circle ml-2"></i>
                الملف الشخصي
            </a>

            <!-- زر الطباعة -->
            <button onclick="window.print()"
                    class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                <i class="fas fa-print ml-2"></i>
                طباعة التقرير
            </button>
        </div>
    </div>
</div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Treatment Details -->
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-stethoscope text-blue-600 ml-2"></i>
                        تفاصيل العلاج
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">نوع العلاج</h4>
                                <p class="text-gray-900"><?= htmlspecialchars($treatment_type_name) ?></p>
                            </div>
                            
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">تاريخ العلاج</h4>
                                <p class="text-gray-900">
                                    <?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?>
                                    (<?= date('l', strtotime($treatment['treatment_date'])) ?>)
                                </p>
                            </div>
                            
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">الطبيب المعالج</h4>
                                <p class="text-gray-900">د. <?= htmlspecialchars($treatment['doctor_name']) ?></p>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if ($treatment['cost']): ?>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">التكلفة</h4>
                                <p class="text-2xl font-bold text-blue-600"><?= number_format($treatment['cost'], 2) ?> ليرة سورية</p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">حالة الدفع</h4>
                                <?php
                                $payment_classes = [
                                    'paid' => 'bg-green-100 text-green-800',
                                    'partial' => 'bg-yellow-100 text-yellow-800',
                                    'unpaid' => 'bg-red-100 text-red-800'
                                ];
                                $payment_texts = [
                                    'paid' => 'مدفوع بالكامل',
                                    'partial' => 'مدفوع جزئياً',
                                    'unpaid' => 'غير مدفوع'
                                ];
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?= $payment_classes[$treatment['payment_status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                    <?= $payment_texts[$treatment['payment_status']] ?? 'غير محدد' ?>
                                </span>
                            </div>
                            
                            <?php if ($treatment['next_appointment_date']): ?>
                            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <h4 class="font-semibold text-yellow-700 mb-2">موعد المتابعة</h4>
                                <p class="text-yellow-800">
                                    <?= date('d/m/Y', strtotime($treatment['next_appointment_date'])) ?>
                                    <?php if (strtotime($treatment['next_appointment_date']) < strtotime('today')): ?>
                                        <span class="text-red-600 font-bold">(متأخر)</span>
                                    <?php elseif (strtotime($treatment['next_appointment_date']) <= strtotime('+3 days')): ?>
                                        <span class="text-orange-600 font-bold">(قريب)</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Symptoms and Diagnosis -->
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-search text-green-600 ml-2"></i>
                        الأعراض والتشخيص
                    </h3>
                    
                    <?php if ($treatment['symptoms']): ?>
                    <div class="mb-6">
                        <h4 class="font-semibold text-gray-700 mb-3">الأعراض</h4>
                        <div class="p-4 bg-blue-50 border-r-4 border-blue-400 rounded">
                            <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($treatment['symptoms'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-6">
                        <h4 class="font-semibold text-gray-700 mb-3">التشخيص</h4>
                        <div class="p-4 bg-green-50 border-r-4 border-green-400 rounded">
                            <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($treatment['diagnosis'])) ?></p>
                        </div>
                    </div>
                    
                    <div>
                        <h4 class="font-semibold text-gray-700 mb-3">تفاصيل العلاج المقدم</h4>
                        <div class="p-4 bg-purple-50 border-r-4 border-purple-400 rounded">
                            <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($treatment['treatment_details'])) ?></p>
                        </div>
                    </div>
                </div>

                <!-- Medications -->
                <?php if ($treatment['medications']): ?>
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-pills text-orange-600 ml-2"></i>
                        الأدوية الموصوفة
                    </h3>
                    
                    <div class="p-4 bg-orange-50 border-r-4 border-orange-400 rounded">
                        <div class="flex items-start">
                            <i class="fas fa-prescription-bottle text-orange-600 mt-1 ml-3"></i>
                            <div>
                                <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($treatment['medications'])) ?></p>
                                <div class="mt-3 p-2 bg-yellow-100 border border-yellow-300 rounded">
                                    <p class="text-sm text-yellow-800">
                                        <i class="fas fa-exclamation-triangle ml-1"></i>
                                        <strong>تنبيه:</strong> يجب اتباع تعليمات الطبيب بدقة عند تناول الأدوية
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Treatment Stages Progress -->
                <?php if (!empty($treatment_stages)): ?>
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-2xl font-bold text-gray-800">
                            <i class="fas fa-tasks text-purple-600 ml-2"></i>
                            مراحل العلاج
                        </h3>
                        <div class="flex items-center">
                            <div class="relative w-16 h-16">
                                <svg class="w-16 h-16 progress-ring">
                                    <circle cx="32" cy="32" r="28" stroke="#e5e7eb" stroke-width="4" fill="none"></circle>
                                    <circle cx="32" cy="32" r="28" stroke="#10b981" stroke-width="4" fill="none"
                                            stroke-dasharray="175.9"
                                            stroke-dashoffset="<?= 175.9 - (175.9 * $stages_progress['percentage'] / 100) ?>"
                                            class="progress-ring-fill"></circle>
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-sm font-bold text-gray-700"><?= $stages_progress['percentage'] ?>%</span>
                                </div>
                            </div>
                            <div class="mr-4 text-left">
                                <div class="text-sm text-gray-600">التقدم الإجمالي</div>
                                <div class="text-lg font-bold text-gray-800"><?= $stages_progress['completed'] ?>/<?= $stages_progress['total'] ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="stage-timeline">
                        <?php foreach ($treatment_stages as $index => $stage): ?>
                            <?php
                                $is_completed = isset($stage['completed']) && $stage['completed'];
                                // مرحلة أُغلقت عند إنهاء العلاج دون أن تُنفَّذ
                                $is_skipped = $is_completed && !empty($stage['skipped']);
                                $is_current = !$is_completed && ($index == 0 || (isset($treatment_stages[$index-1]['completed']) && $treatment_stages[$index-1]['completed']));
                                $stage_class = $is_completed ? 'completed' : ($is_current ? 'in-progress' : '');
                            ?>
                            <div class="stage-item <?= $stage_class ?> mb-6 last:mb-0">
                                <div class="bg-gray-50 rounded-lg p-4 <?= $is_skipped ? 'border border-gray-300' : ($is_completed ? 'bg-green-50 border border-green-200' : ($is_current ? 'bg-yellow-50 border border-yellow-200' : '')) ?>">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h4 class="font-semibold text-gray-800 mb-2">
                                                <?php if ($is_skipped): ?>
                                                    <i class="fas fa-minus-circle text-gray-500 ml-2"></i>
                                                <?php elseif ($is_completed): ?>
                                                    <i class="fas fa-check-circle text-green-600 ml-2"></i>
                                                <?php elseif ($is_current): ?>
                                                    <i class="fas fa-clock text-yellow-600 ml-2"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-circle text-gray-400 ml-2"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($stage['title'] ?? 'مرحلة غير محددة') ?>
                                            </h4>
                                            <p class="text-gray-600 text-sm mb-2">
                                                <?= htmlspecialchars($stage['description'] ?? '') ?>
                                            </p>
                                            <?php if (!empty($stage['duration'])): ?>
                                                <p class="text-xs text-gray-500 mb-2">
                                                    <i class="fas fa-clock ml-1"></i>
                                                    المدة المتوقعة: <?= htmlspecialchars($stage['duration']) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php $stage_completed_date = normalizeStageDate($stage['completedDate'] ?? null); ?>
                                            <?php if ($is_completed && !$is_skipped && $stage_completed_date): ?>
                                                <p class="text-xs text-green-600">
                                                    <i class="fas fa-calendar-check ml-1"></i>
                                                    تم الإكمال: <?= date('d/m/Y', strtotime($stage_completed_date)) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($stage['notes'])): ?>
                                                <div class="mt-2 p-2 bg-white border border-gray-200 rounded text-xs text-gray-700">
                                                    <i class="fas fa-sticky-note ml-1"></i>
                                                    <?= htmlspecialchars($stage['notes']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-left">
                                            <?php if ($is_skipped): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">
                                                    لم تُنفَّذ
                                                </span>
                                            <?php elseif ($is_completed): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    مكتمل
                                                </span>
                                            <?php elseif ($is_current): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    جاري العمل
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                    في الانتظار
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($stages_progress['percentage'] < 100): ?>
                        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-blue-800 font-semibold">
                                        <i class="fas fa-info-circle ml-1"></i>
                                        العلاج قيد التنفيذ
                                    </p>
                                    <p class="text-sm text-blue-600">متبقي <?= $stages_progress['total'] - $stages_progress['completed'] ?> مرحلة لإكمال العلاج</p>
                                </div>
                                <a href="treatment_new.php?complete_id=<?= $treatment['id'] ?>"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm transition">
                                    <i class="fas fa-play ml-1"></i>
                                    متابعة العلاج
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                            <p class="text-green-800 font-semibold text-center">
                                <i class="fas fa-check-circle ml-2"></i>
                                تم إكمال جميع مراحل العلاج بنجاح
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Teeth Information -->
                <?php if (!empty($treatment['teeth_numbers'])): ?>
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-tooth text-blue-600 ml-2"></i>
                        الأسنان المعالجة
                    </h3>

                    <?php
                        $teeth_numbers = json_decode($treatment['teeth_numbers'], true) ?: [];
                        if (empty($teeth_numbers) && !empty($treatment['teeth_numbers'])) {
                            $teeth_numbers = [$treatment['teeth_numbers']];
                        }
                    ?>

                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-3">
                        <?php for ($i = 1; $i <= 32; $i++): ?>
                            <?php $is_treated = in_array($i, $teeth_numbers) || in_array((string)$i, $teeth_numbers); ?>
                            <div class="text-center">
                                <div class="tooth-item w-10 h-10 mx-auto rounded-lg flex items-center justify-center text-sm font-semibold
                                    <?= $is_treated ? 'tooth-treated' : 'bg-gray-100 text-gray-500 border border-gray-300 hover:bg-gray-200' ?>">
                                    <?= $i ?>
                                </div>
                                <?php if ($is_treated): ?>
                                    <div class="text-xs text-blue-600 mt-1">
                                        <i class="fas fa-check"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <?php if (!empty($teeth_numbers)): ?>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle ml-1"></i>
                                <strong>الأسنان المعالجة:</strong>
                                <?= implode(', ', array_map(function($tooth) { return 'السن رقم ' . $tooth; }, $teeth_numbers)) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Additional Notes -->
                <?php if ($treatment['notes']): ?>
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-sticky-note text-indigo-600 ml-2"></i>
                        ملاحظات إضافية
                    </h3>

                    <div class="p-4 bg-indigo-50 border-r-4 border-indigo-400 rounded">
                        <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($treatment['notes'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment History -->
                <?php if ($treatment['cost'] && $treatment['cost'] > 0): ?>
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in info-card">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-bold text-gray-800">
                            <i class="fas fa-credit-card text-green-600 ml-2"></i>
                            تاريخ الدفعات
                        </h3>
                        <div class="text-left">
                            <div class="text-sm text-gray-600">إجمالي المدفوع</div>
                            <div class="text-2xl font-bold text-green-600"><?= number_format($treatment['total_paid'], 2) ?> ليرة سورية</div>
                            <?php if ($remaining_balance > 0): ?>
                                <div class="text-sm text-red-600">متبقي: <?= number_format($remaining_balance, 2) ?> ليرة سورية</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($payments)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-credit-card text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">لم يتم تسجيل أي دفعات لهذا العلاج</p>
                            <?php if ($remaining_balance > 0): ?>
                                <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $treatment['patient_id'] ?>&treatment_id=<?= $treatment['id'] ?>" 
                                   class="mt-4 inline-block bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                                    <i class="fas fa-plus ml-1"></i>
                                    إضافة دفعة
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="overflow-hidden">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-700">التاريخ</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-700">المبلغ</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-700">طريقة الدفع</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-700">رقم الإيصال</th>
                                        <th class="text-right py-3 px-4 font-semibold text-gray-700">المسجل بواسطة</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($payments as $payment): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div class="text-gray-900"><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></div>
                                                <div class="text-sm text-gray-500"><?= date('H:i', strtotime($payment['payment_date'])) ?></div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-semibold text-green-600"><?= number_format($payment['amount'], 2) ?> ليرة سورية</span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <?php
                                                $methods = [
                                                    'cash' => 'نقداً',
                                                    'card' => 'بطاقة ائتمانية',
                                                    'bank_transfer' => 'تحويل بنكي',
                                                    'insurance' => 'تأمين'
                                                ];
                                                ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?= $methods[$payment['payment_method']] ?? $payment['payment_method'] ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-gray-900">
                                                <?= $payment['receipt_number'] ?: '-' ?>
                                            </td>
                                            <td class="py-3 px-4 text-gray-900">
                                                <?= htmlspecialchars($payment['created_by_name'] ?: 'غير معروف') ?>
                                            </td>
                                        </tr>
                                        <?php if ($payment['notes']): ?>
                                            <tr>
                                                <td colspan="5" class="py-2 px-4 bg-gray-50">
                                                    <div class="text-sm text-gray-600">
                                                        <i class="fas fa-sticky-note ml-1"></i>
                                                        <strong>ملاحظات:</strong> <?= htmlspecialchars($payment['notes']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td class="py-3 px-4 font-semibold text-gray-700">الإجمالي</td>
                                        <td class="py-3 px-4 font-semibold text-green-600"><?= number_format($treatment['total_paid'], 2) ?> ليرة سورية</td>
                                        <td colspan="3" class="py-3 px-4"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <?php if ($remaining_balance > 0): ?>
                            <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-red-800 font-semibold">
                                            <i class="fas fa-exclamation-triangle ml-1"></i>
                                            مبلغ متبقي: <?= number_format($remaining_balance, 2) ?> ليرة سورية
                                        </p>
                                        <p class="text-sm text-red-600">يحتاج دفعة إضافية لإكمال المبلغ</p>
                                    </div>
                                    <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $treatment['patient_id'] ?>&treatment_id=<?= $treatment['id'] ?>" 
                                       class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm transition">
                                        <i class="fas fa-plus ml-1"></i>
                                        إضافة دفعة
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Patient Information -->
                <div class="bg-white rounded-lg shadow-lg p-6 fade-in info-card">
                    <h4 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-user text-blue-600 ml-2"></i>
                        معلومات المريض
                    </h4>
                    
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-user text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">الاسم:</span>
                            <span class="font-semibold text-gray-900 mr-2"><?= htmlspecialchars($treatment['patient_name']) ?></span>
                        </div>
                        
                        <div class="flex items-center">
                            <i class="fas fa-phone text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">الهاتف:</span>
                            <a href="tel:<?= $treatment['phone'] ?>" class="font-semibold text-green-600 hover:text-green-800 mr-2">
                                <?= $treatment['phone'] ?>
                            </a>
                        </div>
                        
                        <div class="flex items-center">
                            <i class="fas fa-birthday-cake text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">العمر:</span>
                            <span class="font-semibold text-gray-900 mr-2"><?= $treatment['age'] ?> سنة</span>
                        </div>
                        
                        <div class="flex items-center">
                            <i class="fas fa-<?= $treatment['gender'] === 'male' ? 'mars' : 'venus' ?> text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">الجنس:</span>
                            <span class="font-semibold text-gray-900 mr-2"><?= $treatment['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                        </div>
                        
                        <?php if ($treatment['emergency_contact']): ?>
                        <div class="flex items-center">
                            <i class="fas fa-phone-alt text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">هاتف الطوارئ:</span>
                            <a href="tel:<?= $treatment['emergency_contact'] ?>" class="font-semibold text-red-600 hover:text-red-800 mr-2">
                                <?= $treatment['emergency_contact'] ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($treatment['blood_type']): ?>
                        <div class="flex items-center">
                            <i class="fas fa-tint text-gray-500 ml-2 w-4"></i>
                            <span class="text-gray-600 text-sm">فصيلة الدم:</span>
                            <span class="font-semibold text-red-600 mr-2"><?= htmlspecialchars($treatment['blood_type']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-6 pt-4 border-t">
                        <a href="patients.php?id=<?= $treatment['patient_id'] ?>" 
                           class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-eye ml-1"></i>
                            عرض ملف المريض
                        </a>
                    </div>
                </div>

                <!-- Medical Alerts -->
                <?php if ($treatment['medical_history'] || $treatment['allergies']): ?>
                <div class="medical-alert rounded-lg shadow-lg p-6 fade-in border-r-4 border-red-500">
                    <h4 class="text-lg font-bold text-red-800 mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 ml-2"></i>
                        تنبيهات طبية مهمة
                    </h4>
                    
                    <?php if ($treatment['medical_history']): ?>
                    <div class="mb-4">
                        <h5 class="font-semibold text-red-700 mb-2">التاريخ المرضي</h5>
                        <div class="p-3 bg-white border border-red-200 rounded">
                            <p class="text-red-800 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($treatment['medical_history'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($treatment['allergies']): ?>
                    <div>
                        <h5 class="font-semibold text-red-700 mb-2">الحساسية</h5>
                        <div class="p-3 bg-white border border-red-200 rounded">
                            <p class="text-red-800 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($treatment['allergies'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

<!-- Previous Treatments - محدث -->
<?php if (!empty($previous_treatments)): ?>
<div class="bg-white rounded-lg shadow-lg p-6 fade-in info-card">
    <h4 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-history text-purple-600 ml-2"></i>
        العلاجات السابقة
    </h4>
    
    <div class="space-y-3 max-h-64 overflow-y-auto">
        <?php foreach ($previous_treatments as $prev_treatment): ?>
            <div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h6 class="font-semibold text-gray-800 text-sm">
                            <?= htmlspecialchars($prev_treatment['treatment_type']) ?>
                        </h6>
                        <p class="text-xs text-gray-600 mt-1">
                            <?= date('d/m/Y', strtotime($prev_treatment['treatment_date'])) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">
                            د. <?= htmlspecialchars($prev_treatment['doctor_name']) ?>
                        </p>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="treatment_details.php?id=<?= $prev_treatment['id'] ?>" 
                           class="text-blue-600 hover:text-blue-800 text-xs">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="treatments.php?action=edit&id=<?= $prev_treatment['id'] ?>" 
                           class="text-purple-600 hover:text-purple-800 text-xs">
                            <i class="fas fa-edit"></i>
                        </a>
                    </div>
                </div>
                <?php if ($prev_treatment['cost']): ?>
                    <div class="text-xs text-gray-500 mt-2">
                        <span class="font-medium"><?= number_format($prev_treatment['cost'], 2) ?> ليرة سورية</span>
                        <span class="mr-2 px-1.5 py-0.5 rounded-full text-xs <?php
                            echo match($prev_treatment['payment_status']) {
                                'paid' => 'bg-green-100 text-green-700',
                                'partial' => 'bg-yellow-100 text-yellow-700',
                                'unpaid' => 'bg-red-100 text-red-700',
                                default => 'bg-gray-100 text-gray-700'
                            };
                        ?>">
                            <?php
                            echo match($prev_treatment['payment_status']) {
                                'paid' => 'مدفوع',
                                'partial' => 'جزئي',
                                'unpaid' => 'غير مدفوع',
                                default => 'غير محدد'
                            };
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="mt-4 pt-4 border-t">
        <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>#treatments" 
           class="text-purple-600 hover:text-purple-800 text-sm flex items-center">
            <i class="fas fa-history ml-1"></i>
            عرض جميع العلاجات
        </a>
    </div>
</div>
<?php endif; ?>

                <!-- Treatment Status Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 fade-in info-card">
                    <h4 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-chart-line text-blue-600 ml-2"></i>
                        حالة العلاج
                    </h4>

                    <div class="space-y-4">
                        <!-- حالة العلاج -->
                        <div class="p-3 rounded-lg <?php
                            echo match($treatment['status']) {
                                'completed' => 'bg-green-50 border border-green-200',
                                'in_progress' => 'bg-yellow-50 border border-yellow-200',
                                'scheduled' => 'bg-blue-50 border border-blue-200',
                                'cancelled' => 'bg-red-50 border border-red-200',
                                default => 'bg-gray-50 border border-gray-200'
                            };
                        ?>">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">الحالة:</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php
                                    echo match($treatment['status']) {
                                        'completed' => 'bg-green-100 text-green-800',
                                        'in_progress' => 'bg-yellow-100 text-yellow-800',
                                        'scheduled' => 'bg-blue-100 text-blue-800',
                                        'cancelled' => 'bg-red-100 text-red-800',
                                        default => 'bg-gray-100 text-gray-800'
                                    };
                                ?>">
                                    <?php
                                    echo match($treatment['status']) {
                                        'completed' => 'مكتمل',
                                        'in_progress' => 'قيد التنفيذ',
                                        'scheduled' => 'مجدول',
                                        'cancelled' => 'ملغي',
                                        default => 'غير محدد'
                                    };
                                    ?>
                                </span>
                            </div>
                        </div>

                        <!-- تقدم المراحل -->
                        <?php if (!empty($treatment_stages)): ?>
                        <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">تقدم المراحل:</span>
                                <span class="text-sm font-bold text-gray-800"><?= $stages_progress['completed'] ?>/<?= $stages_progress['total'] ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                     style="width: <?= $stages_progress['percentage'] ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-600 mt-1 text-center"><?= $stages_progress['percentage'] ?>% مكتمل</div>
                        </div>
                        <?php endif; ?>

                        <!-- حالة الدفع -->
                        <div class="p-3 bg-gray-50 border border-gray-200 rounded-lg">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">حالة الدفع:</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $payment_classes[$treatment['payment_status']] ?? 'bg-gray-100 text-gray-800' ?>">
                                    <?= $payment_texts[$treatment['payment_status']] ?? 'غير محدد' ?>
                                </span>
                            </div>
                            <?php if ($treatment['cost'] && $remaining_balance > 0): ?>
                                <div class="mt-2 text-xs text-red-600">
                                    متبقي: <?= number_format($remaining_balance, 2) ?> ليرة سورية
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-lg shadow-lg p-6 fade-in info-card">
                    <h4 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-bolt text-orange-600 ml-2"></i>
                        إجراءات سريعة
                    </h4>

                    <div class="space-y-3">
                        <!-- زر متابعة العلاج -->
                        <?php if ($treatment['status'] != 'completed' && !empty($treatment_stages) && $stages_progress['percentage'] < 100): ?>
                        <a href="treatment_new.php?complete_id=<?= $treatment['id'] ?>"
                           class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-play ml-2"></i>
                            متابعة العلاج
                        </a>
                        <?php endif; ?>


                        <!-- زر علاج جديد للمريض -->
                        <a href="treatment_new.php?patient_id=<?= $treatment['patient_id'] ?>"
                           class="w-full bg-purple-500 hover:bg-purple-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-plus ml-2"></i>
                            علاج جديد للمريض
                        </a>

                        <!-- زر الملف الشخصي -->
                        <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>"
                           class="w-full bg-indigo-500 hover:bg-indigo-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-user-circle ml-2"></i>
                            الملف الشخصي الكامل
                        </a>

                        <!-- زر إضافة دفعة -->
                        <?php if ($treatment['cost'] && $remaining_balance > 0): ?>
                        <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $treatment['patient_id'] ?>&treatment_id=<?= $treatment['id'] ?>"
                           class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-credit-card ml-2"></i>
                            إضافة دفعة
                        </a>
                        <?php endif; ?>

                        <!-- زر الطباعة -->
                        <button onclick="window.print()"
                                class="w-full bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded-lg text-sm transition flex items-center justify-center">
                            <i class="fas fa-print ml-2"></i>
                            طباعة التقرير
                        </button>

                        <!-- معلومات الموعد القادم -->
                        <?php if ($treatment['next_appointment_date'] && strtotime($treatment['next_appointment_date']) > time()): ?>
                        <div class="w-full bg-blue-100 text-blue-800 py-2 px-4 rounded-lg text-sm text-center">
                            <i class="fas fa-calendar-check ml-2"></i>
                            موعد متابعة: <?= date('d/m/Y', strtotime($treatment['next_appointment_date'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Print functionality with enhanced styling
        window.addEventListener('beforeprint', function() {
            // Add print styles
            const printStyle = document.createElement('style');
            printStyle.innerHTML = `
                @media print {
                    .no-print { display: none !important; }
                    body { font-size: 12px; color: black; }
                    .medical-alert { 
                        background: #ffebee !important; 
                        border: 2px solid #f44336 !important; 
                    }
                    .treatment-header {
                        background: #e3f2fd !important;
                        color: black !important;
                    }
                    .info-card { 
                        break-inside: avoid; 
                        box-shadow: none !important;
                        border: 1px solid #ddd;
                    }
                    h1, h2, h3, h4 { color: black !important; }
                    .sidebar { page-break-before: always; }
                    table { font-size: 11px; }
                }
            `;
            document.head.appendChild(printStyle);
            
            // Hide navigation and buttons
            document.querySelectorAll('nav, header .space-x-4, .no-print').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restore display
            document.querySelectorAll('nav, header .space-x-4, .no-print').forEach(el => {
                el.style.display = '';
            });
        });
        
        // Scroll to medical alerts if present
        if (document.querySelector('.medical-alert')) {
            setTimeout(() => {
                const alert = document.querySelector('.medical-alert');
                alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert.style.animation = 'pulse 2s ease-in-out 3';
            }, 1000);
        }
    </script>

    <style media="print">
        @page { 
            margin: 1cm; 
            size: A4;
        }
        .print-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
    </style>
</body>
</html>