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
$patient_id = $_GET['id'] ?? 0;

if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

// جلب بيانات المريض
try {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               COUNT(DISTINCT t.id) as treatment_count,
               MAX(t.treatment_date) as last_treatment_date,
               COALESCE(SUM(t.cost), 0) as total_cost,
               COALESCE(SUM(pay.amount), 0) as total_paid,
               (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(pay.amount), 0)) as remaining_balance
        FROM patients p
        LEFT JOIN treatments t ON p.id = t.patient_id AND t.doctor_id = ?
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$doctor_id, $patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Location: patients.php');
        exit;
    }
} catch (PDOException $e) {
    $error_message = "خطأ في جلب بيانات المريض: " . $e->getMessage();
    $patient = null;
}

// جلب حالات العلاج من قاعدة البيانات
try {
    $treatment_colors_stmt = $pdo->query("SELECT status_code, name_ar, color FROM treatment_colors WHERE is_active = TRUE");
    $treatment_colors_data = $treatment_colors_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $treatment_colors = [];
    foreach ($treatment_colors_data as $color) {
        // Map specific colors to Tailwind CSS classes
        $colorClass = 'text-gray-600'; // default
        switch ($color['color']) {
            case '#4ade80': $colorClass = 'text-green-500'; break;
            case '#ef4444': $colorClass = 'text-red-500'; break;
            case '#3b82f6': $colorClass = 'text-blue-600'; break;
            case '#f59e0b': $colorClass = 'text-yellow-600'; break;
            case '#8b5cf6': $colorClass = 'text-purple-600'; break;
            case '#6b7280': $colorClass = 'text-gray-600'; break;
            case '#06b6d4': $colorClass = 'text-cyan-600'; break;
            case '#f97316': $colorClass = 'text-orange-600'; break;
            case '#dc2626': $colorClass = 'text-red-600'; break;
        }
        
        $treatment_colors[$color['status_code']] = [
            'text' => $color['name_ar'],
            'color' => $colorClass
        ];
    }
} catch (PDOException $e) {
    $treatment_colors = [];
}

// جلب تاريخ العلاجات
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               u.full_name as doctor_name,
               COALESCE(SUM(pay.amount), 0) as total_paid
        FROM treatments t
        LEFT JOIN users u ON t.doctor_id = u.id
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE t.patient_id = ?
        GROUP BY t.id
        ORDER BY t.treatment_date DESC, t.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatments = [];
}

// جلب المعالجات الحالية (غير المكتملة)
try {
    $stmt = $pdo->prepare("
        SELECT t.*,
               u.full_name as doctor_name,
               CASE
                   WHEN (t.status = 'completed' OR t.completed_at IS NOT NULL OR (t.completion_notes IS NOT NULL AND t.completion_notes != ''))
                        AND (t.treatment_stages IS NULL OR t.treatment_stages = '' OR t.treatment_stages = '[]' OR
                             JSON_EXTRACT(t.treatment_stages, '$[*].completed') IS NULL OR
                             JSON_CONTAINS(JSON_EXTRACT(t.treatment_stages, '$[*].completed'), 'false') = 0)
                   THEN 'completed'
                   ELSE 'in_progress'
               END as treatment_status
        FROM treatments t
        LEFT JOIN users u ON t.doctor_id = u.id
        WHERE t.patient_id = ?
        ORDER BY t.treatment_date DESC, t.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $all_treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // فصل العلاجات الحالية عن المكتملة
    $current_treatments = [];
    $completed_treatments = [];

    foreach ($all_treatments as $treatment) {
        if ($treatment['treatment_status'] === 'completed') {
            $completed_treatments[] = $treatment;
        } else {
            $current_treatments[] = $treatment;
        }
    }
} catch (PDOException $e) {
    $current_treatments = [];
    $completed_treatments = [];
}

// جلب تاريخ المدفوعات
try {
    $stmt = $pdo->prepare("
        SELECT pay.*, 
               t.treatment_type,
               u.full_name as created_by_name
        FROM payments pay
        LEFT JOIN treatments t ON pay.treatment_id = t.id
        LEFT JOIN users u ON pay.created_by = u.id
        WHERE pay.patient_id = ?
        ORDER BY pay.payment_date DESC
    ");
    $stmt->execute([$patient_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// جلب الملاحظات
try {
    $stmt = $pdo->prepare("
        SELECT n.*, 
               u.full_name as created_by_name
        FROM patient_notes n
        LEFT JOIN users u ON n.created_by = u.id
        WHERE n.patient_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notes = [];
}

// معالجة إضافة ملاحظة جديدة
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO patient_notes (patient_id, note_content, note_type, created_by) 
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([
            $patient_id,
            $_POST['note_content'],
            $_POST['note_type'] ?? 'general',
            $_SESSION['user_id']
        ]);
        
        if ($result) {
            header("Location: patient_profile.php?id=" . $patient_id);
            exit;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إضافة الملاحظة: " . $e->getMessage();
    }
}

// جلب أنواع العلاجات من قاعدة البيانات
$treatment_types_map = [];
try {
    $stmt = $pdo->prepare("SELECT code, name_ar FROM treatment_types WHERE is_active = 1 ORDER BY sort_order, name_ar");
    $stmt->execute();
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($types as $type) {
        $treatment_types_map[$type['code']] = $type['name_ar'];
    }
} catch (PDOException $e) {
    // إذا لم تكن الجدولة موجودة، استخدم القائمة الافتراضية
    $treatment_types_map = [
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
}

// Header configuration
$pageTitle = 'ملف المريض';
$pageIcon = 'fas fa-user-circle';
$pageSubtitle = 'تفاصيل المريض والتاريخ المرضي';
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
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .patient-tab {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .patient-tab:hover {
            color: #3b82f6 !important;
        }

        .medical-alert {
            background: linear-gradient(45deg, #fee2e2, #fef2f2);
            border-right: 4px solid #ef4444;
            padding: 1rem;
            border-radius: 0.5rem;
        }

        .info-card {
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        @media print {
            .no-print { display: none !important; }
            .tab-content { display: block !important; }
            body { font-size: 12px; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <?php if (!$patient): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="bg-white rounded-lg shadow-lg p-16 text-center">
                <i class="fas fa-user-times text-6xl text-gray-300 mb-6"></i>
                <h3 class="text-2xl font-bold text-gray-900 mb-4">المريض غير موجود</h3>
                <p class="text-gray-600 mb-8">لم يتم العثور على المريض المطلوب.</p>
                <a href="patients.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                    العودة لقائمة المرضى
                </a>
            </div>
        </div>
    <?php else: ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Main Content -->
            <div class="flex-1">
            <!-- Tabs Navigation -->
            <div class="bg-white rounded-lg shadow-lg mb-6">
                <div class="flex border-b">
                    <button class="patient-tab active flex items-center px-6 py-4 border-b-2 border-blue-500 text-blue-600 font-medium" data-tab="overview">
                        <i class="fas fa-user ml-2"></i>
                        المعلومات الشخصية
                    </button>
                    <button class="patient-tab flex items-center px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium" data-tab="treatments">
                        <i class="fas fa-tooth ml-2"></i>
                        العلاجات
                    </button>
                    <button class="patient-tab flex items-center px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium" data-tab="payments">
                        <i class="fas fa-credit-card ml-2"></i>
                        المدفوعات
                    </button>
                    <button class="patient-tab flex items-center px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium" data-tab="notes">
                        <i class="fas fa-sticky-note ml-2"></i>
                        الملاحظات
                    </button>
                </div>
            </div>
            
            <!-- Tab Contents -->
            
            <!-- Overview Tab -->
            <div class="tab-content active" id="overview">
                <!-- Patient Header Card -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-700 text-white rounded-lg shadow-xl p-8 mb-8">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="bg-white bg-opacity-20 p-6 rounded-full ml-6">
                                <i class="fas fa-user text-4xl text-white"></i>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold mb-2"><?= htmlspecialchars($patient['name']) ?></h1>
                                <div class="grid grid-cols-2 gap-4 text-lg">
                                    <div class="flex items-center">
                                        <i class="fas fa-phone ml-2"></i>
                                        <a href="tel:<?= $patient['phone'] ?>" class="hover:underline"><?= $patient['phone'] ?></a>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-birthday-cake ml-2"></i>
                                        <?= $patient['age'] ?> سنة
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-<?= $patient['gender'] === 'male' ? 'mars' : 'venus' ?> ml-2"></i>
                                        <?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?>
                                    </div>
                                    <?php if ($patient['last_treatment_date']): ?>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar ml-2"></i>
                                        آخر زيارة: <?= date('d/m/Y', strtotime($patient['last_treatment_date'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="text-right">
                            <div class="text-lg opacity-90">رقم الملف</div>
                            <div class="text-4xl font-bold"><?= str_pad($patient['id'], 6, '0', STR_PAD_LEFT) ?></div>
                            <div class="text-sm opacity-90 mt-2">
                                مريض منذ <?= date('d/m/Y', strtotime($patient['registration_date'])) ?>
                            </div>
                            <div class="text-sm opacity-90">
                                <?= $patient['status'] === 'active' ? 'نشط' : 'غير نشط' ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow p-6 text-center">
                        <div class="text-3xl font-bold text-blue-600"><?= $patient['treatment_count'] ?></div>
                        <div class="text-sm text-gray-600">علاج مكتمل</div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6 text-center">
                        <div class="text-3xl font-bold text-green-600"><?= number_format($patient['total_cost'], 2) ?></div>
                        <div class="text-sm text-gray-600">إجمالي التكلفة (ليرة سورية)</div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6 text-center">
                        <div class="text-3xl font-bold text-purple-600"><?= number_format($patient['total_paid'], 2) ?></div>
                        <div class="text-sm text-gray-600">المبلغ المدفوع (ليرة سورية)</div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-6 text-center">
                        <div class="text-3xl font-bold <?= $patient['remaining_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">
                            <?= number_format($patient['remaining_balance'], 2) ?>
                        </div>
                        <div class="text-sm text-gray-600">الرصيد المتبقي (ليرة سورية)</div>
                    </div>
                </div>
                
                <!-- Comprehensive Patient Information -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    <!-- Personal Information -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-user text-blue-600 ml-2"></i>
                            المعلومات الشخصية
                        </h3>

                        <div class="space-y-4">
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">الاسم الكامل</label>
                                <p class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($patient['name']) ?></p>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <label class="text-sm font-medium text-gray-600">العمر</label>
                                    <p class="text-lg font-semibold text-gray-900"><?= $patient['age'] ?> سنة</p>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <label class="text-sm font-medium text-gray-600">الجنس</label>
                                    <p class="text-lg font-semibold text-gray-900"><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></p>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-address-book text-green-600 ml-2"></i>
                            معلومات الاتصال
                        </h3>

                        <div class="space-y-4">
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">رقم الهاتف</label>
                                <p class="text-lg font-semibold text-gray-900">
                                    <a href="tel:<?= $patient['phone'] ?>" class="text-green-600 hover:text-green-800">
                                        <?= $patient['phone'] ?>
                                    </a>
                                </p>
                            </div>

                            <?php if ($patient['email']): ?>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">البريد الإلكتروني</label>
                                <p class="text-lg font-semibold text-gray-900">
                                    <a href="mailto:<?= $patient['email'] ?>" class="text-blue-600 hover:text-blue-800">
                                        <?= htmlspecialchars($patient['email']) ?>
                                    </a>
                                </p>
                            </div>
                            <?php endif; ?>

                            <?php if ($patient['address']): ?>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">العنوان</label>
                                <p class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($patient['address']) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($patient['emergency_contact']): ?>
                            <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                                <label class="text-sm font-medium text-red-600">هاتف الطوارئ</label>
                                <p class="text-lg font-semibold text-red-700">
                                    <a href="tel:<?= $patient['emergency_contact'] ?>" class="hover:underline">
                                        <?= $patient['emergency_contact'] ?>
                                    </a>
                                </p>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-heartbeat text-red-600 ml-2"></i>
                            المعلومات الطبية
                        </h3>

                        <div class="space-y-4">
                            <?php if ($patient['blood_type']): ?>
                            <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                                <label class="text-sm font-medium text-red-600">فصيلة الدم</label>
                                <p class="text-lg font-semibold text-red-700"><?= htmlspecialchars($patient['blood_type']) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($patient['medical_history']): ?>
                            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <label class="text-sm font-medium text-yellow-600 mb-2 block">
                                    <i class="fas fa-history ml-1"></i>
                                    التاريخ المرضي
                                </label>
                                <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($patient['medical_history'])) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($patient['allergies']): ?>
                            <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                                <label class="text-sm font-medium text-red-600 mb-2 block">
                                    <i class="fas fa-exclamation-triangle ml-1"></i>
                                    الحساسية
                                </label>
                                <p class="text-red-800 font-medium leading-relaxed"><?= nl2br(htmlspecialchars($patient['allergies'])) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if (!$patient['medical_history'] && !$patient['allergies']): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-notes-medical text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500">لا يوجد تاريخ طبي مسجل</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Financial Info -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-money-bill-wave text-green-600 ml-2"></i>
                            المعلومات المالية
                        </h3>

                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="p-4 bg-green-50 rounded-lg text-center">
                                    <label class="text-sm font-medium text-green-600">إجمالي المدفوع</label>
                                    <p class="text-2xl font-bold text-green-700"><?= number_format($patient['total_paid'], 2) ?></p>
                                    <p class="text-xs text-green-600">ليرة سورية</p>
                                </div>
                                <div class="p-4 <?= $patient['remaining_balance'] > 0 ? 'bg-red-50' : 'bg-green-50' ?> rounded-lg text-center">
                                    <label class="text-sm font-medium <?= $patient['remaining_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">الرصيد المتبقي</label>
                                    <p class="text-2xl font-bold <?= $patient['remaining_balance'] > 0 ? 'text-red-700' : 'text-green-700' ?>"><?= number_format($patient['remaining_balance'], 2) ?></p>
                                    <p class="text-xs <?= $patient['remaining_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">ليرة سورية</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Registration & Status Info -->
                    <div class="bg-white rounded-lg shadow-lg p-6">
                        <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                            <i class="fas fa-info-circle text-indigo-600 ml-2"></i>
                            معلومات التسجيل والحالة
                        </h3>

                        <div class="space-y-4">
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">تاريخ التسجيل</label>
                                <p class="text-lg font-semibold text-gray-900"><?= date('d/m/Y', strtotime($patient['registration_date'])) ?></p>
                            </div>

                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">حالة المريض</label>
                                <p class="text-lg font-semibold">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?= $patient['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $patient['status'] === 'active' ? 'نشط' : 'غير نشط' ?>
                                    </span>
                                </p>
                            </div>

                            <?php if ($patient['last_visit_date']): ?>
                            <div class="p-4 bg-blue-50 rounded-lg">
                                <label class="text-sm font-medium text-blue-600">آخر زيارة</label>
                                <p class="text-lg font-semibold text-blue-700"><?= date('d/m/Y', strtotime($patient['last_visit_date'])) ?></p>
                            </div>
                            <?php endif; ?>

                            <div class="p-4 bg-gray-50 rounded-lg">
                                <label class="text-sm font-medium text-gray-600">عدد العلاجات</label>
                                <p class="text-lg font-semibold text-gray-900"><?= $patient['treatment_count'] ?> علاج</p>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Treatments Tab -->
            <div class="tab-content" id="treatments">
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-800">العلاجات</h3>
                        <a href="treatment_new.php?patient_id=<?= $patient_id ?>"
                           class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                            <i class="fas fa-plus ml-1"></i>
                            علاج جديد
                        </a>
                    </div>
                </div>

                <?php if (empty($current_treatments) && empty($completed_treatments)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-tooth text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد علاجات مسجلة</h3>
                        <p class="text-gray-600 mb-6">لم يتم تسجيل أي علاجات لهذا المريض بعد.</p>
                        <a href="treatment_new.php?patient_id=<?= $patient_id ?>"
                           class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition">
                            إضافة أول علاج
                        </a>
                    </div>
                <?php else: ?>

                    <!-- Current Treatments Section -->
                    <?php if (!empty($current_treatments)): ?>
                    <div class="mb-8">
                        <div class="bg-gradient-to-r from-orange-500 via-orange-500 to-yellow-500 text-white p-4 rounded-t-lg shadow-lg">
                            <h4 class="text-lg font-bold flex items-center">
                                <i class="fas fa-play-circle ml-2 text-black drop-shadow-md"></i>
                                العلاجات الجارية
                                <span class="bg-white bg-opacity-25 px-3 py-1 rounded-full text-sm mr-2 font-semibold shadow-sm"><?= count($current_treatments) ?></span>
                            </h4>
                        </div>

                        <div class="bg-gradient-to-br from-orange-50 to-amber-50 border border-t-0 rounded-b-lg p-6">
                            <div class="space-y-4">
                                <?php foreach ($current_treatments as $treatment): ?>
                                    <div class="bg-gradient-to-r from-orange-100 to-amber-100 border border-orange-300 rounded-lg p-4 hover:shadow-lg hover:from-orange-200 hover:to-amber-200 transition-all duration-300 transform hover:-translate-y-1">
                                        <div class="flex justify-between items-start mb-3">
                                            <div class="flex-1">
                                                <h5 class="font-bold text-orange-800 text-lg"><?= htmlspecialchars($treatment_types_map[$treatment['treatment_type']] ?? $treatment['treatment_type']) ?></h5>
                                                <p class="text-sm text-orange-600 mt-1">بدء العلاج: <?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?></p>
                                                <p class="text-sm text-gray-700">الطبيب: د. <?= htmlspecialchars($treatment['doctor_name'] ?? 'غير محدد') ?></p>
                                            </div>
                                            <div class="text-right">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r from-yellow-200 to-orange-200 text-orange-800 border border-orange-300 shadow-sm">
                                                    <i class="fas fa-clock ml-1"></i>
                                                    قيد التنفيذ
                                                </span>
                                                <?php if ($treatment['next_appointment_date']): ?>
                                                    <div class="text-sm text-orange-600 mt-2">
                                                        <i class="fas fa-calendar ml-1"></i>
                                                        المتابعة: <?= date('d/m/Y', strtotime($treatment['next_appointment_date'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($treatment['diagnosis']): ?>
                                            <div class="mb-3 p-3 bg-gradient-to-r from-blue-100 to-indigo-100 border border-blue-300 rounded-lg shadow-sm">
                                                <strong class="text-blue-800">التشخيص:</strong>
                                                <p class="text-blue-700 mt-1"><?= htmlspecialchars($treatment['diagnosis']) ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($treatment['treatment_stages']): ?>
                                            <?php
                                            $stages = json_decode($treatment['treatment_stages'], true);
                                            if (is_array($stages) && count($stages) > 0):
                                                $completed_stages = array_filter($stages, function($stage) { return isset($stage['completed']) && $stage['completed']; });
                                                $progress_percentage = (count($completed_stages) / count($stages)) * 100;
                                            ?>
                                            <div class="mb-3 p-3 bg-gradient-to-r from-purple-100 to-pink-100 border border-purple-300 rounded-lg shadow-sm">
                                                <div class="flex items-center justify-between mb-2">
                                                    <span class="text-sm font-medium text-purple-800">تقدم المراحل</span>
                                                    <span class="text-xs bg-purple-200 text-purple-700 px-2 py-1 rounded-full"><?= count($completed_stages) ?> من <?= count($stages) ?></span>
                                                </div>
                                                <div class="w-full bg-purple-200 rounded-full h-3 shadow-inner">
                                                    <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-3 rounded-full transition-all duration-500 shadow-sm" style="width: <?= $progress_percentage ?>%"></div>
                                                </div>
                                                <div class="text-xs text-purple-700 mt-2 font-medium"><?= round($progress_percentage) ?>% مكتمل</div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($treatment['teeth_numbers']): ?>
                                            <?php $teeth = json_decode($treatment['teeth_numbers'], true); ?>
                                            <?php if (is_array($teeth) && count($teeth) > 0): ?>
                                            <div class="mb-3">
                                                <span class="text-sm text-gray-600 ml-2">الأسنان المعالجة:</span>
                                                <?php foreach ($teeth as $tooth): ?>
                                                    <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded mr-1"><?= $tooth ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($treatment['cost'] > 0): ?>
                                            <div class="mb-3 p-2 bg-yellow-50 border border-yellow-200 rounded text-sm">
                                                <strong>التكلفة:</strong> <?= number_format($treatment['cost'], 2) ?> ليرة سورية
                                                <?php
                                                $paid = $treatment['total_paid'] ?? 0;
                                                $remaining = $treatment['cost'] - $paid;
                                                ?>
                                                <?php if ($paid > 0): ?>
                                                    <span class="text-green-600 mr-2">(مدفوع: <?= number_format($paid, 2) ?>)</span>
                                                <?php endif; ?>
                                                <?php if ($remaining > 0): ?>
                                                    <span class="text-red-600 mr-2">(متبقي: <?= number_format($remaining, 2) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex justify-end space-x-3 space-x-reverse pt-3 border-t border-orange-200">
                                            <a href="treatment_details.php?id=<?= $treatment['id'] ?>"
                                               class="bg-blue-100 hover:bg-blue-200 text-blue-700 hover:text-blue-800 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200 border border-blue-300">
                                                <i class="fas fa-eye ml-1"></i>عرض التفاصيل
                                            </a>
                                            <a href="treatment_new.php?complete_id=<?= $treatment['id'] ?>"
                                               class="bg-gradient-to-r from-orange-200 to-amber-200 hover:from-orange-300 hover:to-amber-300 text-orange-800 hover:text-orange-900 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200 border border-orange-300">
                                                <i class="fas fa-play ml-1"></i>متابعة العلاج
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Completed Treatments Section -->
                    <?php if (!empty($completed_treatments)): ?>
                    <div class="mb-8">
                        <div class="bg-gradient-to-r from-emerald-600 via-green-600 to-teal-600 text-white p-4 rounded-t-lg shadow-lg">
                            <h4 class="text-lg font-bold flex items-center">
                                <i class="fas fa-check-circle ml-2 text-white drop-shadow-md"></i>
                                العلاجات المكتملة
                                <span class="bg-white bg-opacity-25 px-3 py-1 rounded-full text-sm mr-2 font-semibold shadow-sm"><?= count($completed_treatments) ?></span>
                            </h4>
                        </div>

                        <div class="bg-gradient-to-br from-emerald-50 to-green-50 border border-t-0 rounded-b-lg p-6">
                            <div class="space-y-4">
                                <?php foreach ($completed_treatments as $treatment): ?>
                                    <div class="bg-gradient-to-r from-emerald-100 to-green-100 border border-emerald-300 rounded-lg p-4 hover:shadow-lg hover:from-emerald-200 hover:to-green-200 transition-all duration-300 transform hover:-translate-y-1">
                                        <div class="flex justify-between items-start mb-3">
                                            <div class="flex-1">
                                                <h5 class="font-bold text-emerald-800 text-lg"><?= htmlspecialchars($treatment_types_map[$treatment['treatment_type']] ?? $treatment['treatment_type']) ?></h5>
                                                <p class="text-sm text-emerald-600 mt-1">تاريخ العلاج: <?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?></p>
                                                <p class="text-sm text-gray-700">الطبيب: د. <?= htmlspecialchars($treatment['doctor_name'] ?? 'غير محدد') ?></p>
                                            </div>
                                            <div class="text-right">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gradient-to-r from-emerald-200 to-green-200 text-emerald-800 border border-emerald-300 shadow-sm">
                                                    <i class="fas fa-check ml-1"></i>
                                                    مكتمل
                                                </span>
                                                <?php if ($treatment['completed_at']): ?>
                                                    <div class="text-sm text-emerald-600 mt-2">
                                                        <i class="fas fa-calendar-check ml-1"></i>
                                                        تم في: <?= date('d/m/Y', strtotime($treatment['completed_at'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if ($treatment['diagnosis']): ?>
                                            <div class="mb-3 p-3 bg-gradient-to-r from-blue-100 to-indigo-100 border border-blue-300 rounded-lg shadow-sm">
                                                <strong class="text-blue-800">التشخيص:</strong>
                                                <p class="text-blue-700 mt-1"><?= htmlspecialchars(substr($treatment['diagnosis'], 0, 150)) ?><?= strlen($treatment['diagnosis']) > 150 ? '...' : '' ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($treatment['teeth_numbers']): ?>
                                            <?php $teeth = json_decode($treatment['teeth_numbers'], true); ?>
                                            <?php if (is_array($teeth) && count($teeth) > 0): ?>
                                            <div class="mb-3">
                                                <span class="text-sm text-emerald-700 ml-2 font-medium">الأسنان المعالجة:</span>
                                                <?php foreach ($teeth as $tooth): ?>
                                                    <span class="inline-block bg-gradient-to-r from-emerald-200 to-green-200 text-emerald-800 text-xs px-3 py-1 rounded-full mr-1 border border-emerald-300"><?= $tooth ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($treatment['cost'] > 0): ?>
                                            <div class="mb-3 p-3 bg-gradient-to-r from-green-100 to-emerald-100 border border-green-300 rounded-lg text-sm shadow-sm">
                                                <strong class="text-green-800">التكلفة:</strong> <?= number_format($treatment['cost'], 2) ?> ليرة سورية
                                                <?php
                                                $paid = $treatment['total_paid'] ?? 0;
                                                $remaining = $treatment['cost'] - $paid;
                                                ?>
                                                <?php if ($paid > 0): ?>
                                                    <span class="bg-green-200 text-green-800 px-2 py-1 rounded-full text-xs mr-2 border border-green-300">(مدفوع: <?= number_format($paid, 2) ?>)</span>
                                                <?php endif; ?>
                                                <?php if ($remaining > 0): ?>
                                                    <span class="bg-red-200 text-red-800 px-2 py-1 rounded-full text-xs mr-2 border border-red-300">(متبقي: <?= number_format($remaining, 2) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex justify-end pt-3 border-t border-emerald-200">
                                            <a href="treatment_details.php?id=<?= $treatment['id'] ?>"
                                               class="bg-blue-100 hover:bg-blue-200 text-blue-700 hover:text-blue-800 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200 border border-blue-300">
                                                <i class="fas fa-eye ml-1"></i>عرض التفاصيل
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            
            <!-- Payments Tab -->
            <div class="tab-content" id="payments">
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-800">المدفوعات والرصيد</h3>
                        <?php if ($patient['remaining_balance'] > 0): ?>
                            <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $patient_id ?>" 
                               class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                                <i class="fas fa-plus ml-1"></i>
                                إضافة دفعة
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Balance Card -->
                <div class="balance-card mb-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <h4 class="text-lg font-semibold opacity-90">الرصيد الحالي</h4>
                            <div class="balance-amount">
                                <?= number_format($patient['remaining_balance'], 2) ?> ليرة سورية
                            </div>
                            <div class="balance-status">
                                <?php if ($patient['remaining_balance'] > 0): ?>
                                    <i class="fas fa-exclamation-circle ml-1"></i>
                                    يوجد مبلغ مستحق
                                <?php elseif ($patient['remaining_balance'] < 0): ?>
                                    <i class="fas fa-check-circle ml-1"></i>
                                    رصيد زائد للمريض
                                <?php else: ?>
                                    <i class="fas fa-check-circle ml-1"></i>
                                    الحساب مسدد بالكامل
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm opacity-90">إجمالي التكلفة</div>
                            <div class="text-xl font-bold"><?= number_format($patient['total_cost'], 2) ?> ليرة سورية</div>
                            <div class="text-sm opacity-90 mt-1">
                                مدفوع: <?= number_format($patient['total_paid'], 2) ?> ليرة سورية
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment History -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-700 mb-4">
                        <i class="fas fa-history ml-2"></i>
                        تاريخ المدفوعات
                    </h4>
                    
                    <?php if (empty($payments)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-credit-card text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">لا يوجد سجل مدفوعات</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($payments as $payment): ?>
                                <div class="payment-history-item">
                                    <div>
                                        <div class="font-medium text-gray-900">
                                            <?= date('d/m/Y', strtotime($payment['payment_date'])) ?>
                                            <span class="text-sm text-gray-500 mr-2">
                                                <?= date('H:i', strtotime($payment['payment_date'])) ?>
                                            </span>
                                        </div>
                                        <?php if ($payment['treatment_type']): ?>
                                            <div class="text-sm text-gray-600">
                                                <?= htmlspecialchars($treatment_types_map[$payment['treatment_type']] ?? $payment['treatment_type']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($payment['receipt_number']): ?>
                                            <div class="text-xs text-gray-500">
                                                إيصال رقم: <?= htmlspecialchars($payment['receipt_number']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="text-right">
                                        <div class="payment-amount">
                                            <?= number_format($payment['amount'], 2) ?> ليرة سورية
                                        </div>
                                        <div class="payment-method">
                                            <?php
                                            $methods = [
                                                'cash' => 'نقداً',
                                                'card' => 'بطاقة ائتمانية',
                                                'bank_transfer' => 'تحويل بنكي',
                                                'insurance' => 'تأمين'
                                            ];
                                            echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                                            ?>
                                        </div>
                                        <?php if ($payment['created_by_name']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                بواسطة: <?= htmlspecialchars($payment['created_by_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Notes Tab -->
            <div class="tab-content" id="notes">
                <div class="mb-6">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-bold text-gray-800">الملاحظات</h3>
                        <button onclick="showAddNoteForm()" 
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                            <i class="fas fa-plus ml-1"></i>
                            إضافة ملاحظة
                        </button>
                    </div>
                </div>
                
                <!-- Add Note Form (Hidden by default) -->
                <div id="addNoteForm" class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6" style="display: none;">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_note">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">نوع الملاحظة</label>
                                <select name="note_type" class="w-full p-2 border border-gray-300 rounded text-sm">
                                    <option value="general">عامة</option>
                                    <option value="medical">طبية</option>
                                    <option value="behavioral">سلوكية</option>
                                    <option value="financial">مالية</option>
                                    <option value="reminder">تذكير</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">المحتوى *</label>
                            <textarea name="note_content" required rows="3" 
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                      placeholder="اكتب الملاحظة هنا..."></textarea>
                        </div>
                        
                        <div class="flex space-x-2 space-x-reverse">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm">
                                حفظ الملاحظة
                            </button>
                            <button type="button" onclick="hideAddNoteForm()" 
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded text-sm">
                                إلغاء
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Notes List -->
                <div>
                    <?php if (empty($notes)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-sticky-note text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">لا توجد ملاحظات مسجلة</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($notes as $note): ?>
                                <div class="note-item">
                                    <div class="note-header">
                                        <span>
                                            <?php
                                            $note_types = [
                                                'general' => 'عامة',
                                                'medical' => 'طبية',
                                                'behavioral' => 'سلوكية',
                                                'financial' => 'مالية',
                                                'reminder' => 'تذكير'
                                            ];
                                            echo $note_types[$note['note_type']] ?? 'عامة';
                                            ?>
                                        </span>
                                        <span>
                                            <?= date('d/m/Y H:i', strtotime($note['created_at'])) ?>
                                            <?php if ($note['created_by_name']): ?>
                                                - <?= htmlspecialchars($note['created_by_name']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="note-content">
                                        <?= nl2br(htmlspecialchars($note['note_content'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <!-- Enhanced Sidebar -->
            <div class="lg:w-80 space-y-6">
                <!-- Patient Summary Card -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-100 rounded-xl shadow-lg p-6 border border-blue-200">
                    <div class="text-center mb-4">
                        <div class="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-user text-2xl text-white"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($patient['name']) ?></h3>
                        <p class="text-sm text-gray-600">ملف رقم #<?= str_pad($patient['id'], 6, '0', STR_PAD_LEFT) ?></p>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">الحالة:</span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $patient['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $patient['status'] === 'active' ? 'نشط' : 'غير نشط' ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">العمر:</span>
                            <span class="font-medium"><?= $patient['age'] ?> سنة</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">آخر زيارة:</span>
                            <span class="font-medium"><?= $patient['last_treatment_date'] ? date('d/m/Y', strtotime($patient['last_treatment_date'])) : 'لا توجد' ?></span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-bolt text-orange-500 ml-2"></i>
                        إجراءات سريعة
                    </h4>

                    <div class="space-y-3">
                        <a href="treatment_new.php?patient_id=<?= $patient_id ?>"
                           class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-plus ml-2"></i>
                            علاج جديد
                        </a>

                        <?php if ($patient['remaining_balance'] > 0): ?>
                            <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $patient_id ?>"
                               class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                                <i class="fas fa-credit-card ml-2"></i>
                                إضافة دفعة
                            </a>
                        <?php endif; ?>

                        <button onclick="showAddNoteForm()"
                                class="w-full bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-sticky-note ml-2"></i>
                            إضافة ملاحظة
                        </button>

                        <a href="appointments.php?patient_id=<?= $patient_id ?>"
                           class="w-full bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-calendar-plus ml-2"></i>
                            حجز موعد
                        </a>

                        <button onclick="window.print()"
                                class="w-full bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <i class="fas fa-print ml-2"></i>
                            طباعة الملف
                        </button>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-chart-pie text-green-500 ml-2"></i>
                        الملخص المالي
                    </h4>

                    <div class="space-y-4">
                        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                            <div class="text-center">
                                <p class="text-sm text-green-600 font-medium">إجمالي المدفوع</p>
                                <p class="text-2xl font-bold text-green-700"><?= number_format($patient['total_paid'], 0) ?></p>
                                <p class="text-xs text-green-600">ليرة سورية</p>
                            </div>
                        </div>

                        <div class="<?= $patient['remaining_balance'] > 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200' ?> p-4 rounded-lg border">
                            <div class="text-center">
                                <p class="text-sm <?= $patient['remaining_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?> font-medium">الرصيد المتبقي</p>
                                <p class="text-2xl font-bold <?= $patient['remaining_balance'] > 0 ? 'text-red-700' : 'text-green-700' ?>"><?= number_format($patient['remaining_balance'], 0) ?></p>
                                <p class="text-xs <?= $patient['remaining_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>">ليرة سورية</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                    <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-chart-bar text-indigo-500 ml-2"></i>
                        إحصائيات سريعة
                    </h4>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600"><?= $patient['treatment_count'] ?></div>
                            <div class="text-xs text-blue-600">العلاجات</div>
                        </div>
                        <div class="text-center p-3 bg-purple-50 rounded-lg">
                            <div class="text-2xl font-bold text-purple-600"><?= count($payments) ?></div>
                            <div class="text-xs text-purple-600">المدفوعات</div>
                        </div>
                        <div class="text-center p-3 bg-green-50 rounded-lg">
                            <div class="text-2xl font-bold text-green-600"><?= count($treatments) + count($current_treatments) ?></div>
                            <div class="text-xs text-green-600">الزيارات</div>
                        </div>
                        <div class="text-center p-3 bg-yellow-50 rounded-lg">
                            <div class="text-2xl font-bold text-yellow-600"><?= count($notes) ?></div>
                            <div class="text-xs text-yellow-600">الملاحظات</div>
                        </div>
                    </div>
                </div>

                <!-- Contact Quick Links -->
                <div class="bg-gradient-to-br from-blue-50 to-cyan-100 rounded-xl shadow-lg p-6 border border-blue-200">
                    <h4 class="text-lg font-bold text-blue-800 mb-4 flex items-center">
                        <i class="fas fa-phone text-blue-600 ml-2"></i>
                        تواصل سريع
                    </h4>

                    <div class="space-y-3">
                        <a href="tel:<?= $patient['phone'] ?>"
                           class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg">
                            <i class="fas fa-phone ml-2"></i>
                            اتصال: <?= $patient['phone'] ?>
                        </a>

                        <a href="sms:<?= $patient['phone'] ?>"
                           class="w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg">
                            <i class="fas fa-sms ml-2"></i>
                            رسالة نصية
                        </a>

                        <?php if ($patient['email']): ?>
                            <a href="mailto:<?= $patient['email'] ?>"
                               class="w-full bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white p-3 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg">
                                <i class="fas fa-envelope ml-2"></i>
                                إيميل
                            </a>
                        <?php endif; ?>

                        <?php if ($patient['emergency_contact']): ?>
                            <div class="border-t pt-3 mt-3">
                                <p class="text-xs text-gray-600 mb-2">جهة اتصال الطوارئ:</p>
                                <a href="tel:<?= $patient['emergency_contact'] ?>"
                                   class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white p-2 rounded-lg text-sm font-medium transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg">
                                    <i class="fas fa-phone-alt ml-2"></i>
                                    <?= $patient['emergency_contact'] ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tabs
            initializeTabs();
        });

        function initializeTabs() {
            const tabs = document.querySelectorAll('.patient-tab');
            const contents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;

                    // Remove active class from all tabs and contents
                    tabs.forEach(t => {
                        t.classList.remove('active', 'border-blue-500', 'text-blue-600');
                        t.classList.add('border-transparent', 'text-gray-500');
                    });
                    contents.forEach(c => c.classList.remove('active'));

                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active', 'border-blue-500', 'text-blue-600');
                    this.classList.remove('border-transparent', 'text-gray-500');
                    document.getElementById(targetTab).classList.add('active');
                });
            });
        }
        
        function showAddNoteForm() {
            document.getElementById('addNoteForm').style.display = 'block';
            document.querySelector('textarea[name="note_content"]').focus();
        }
        
        function hideAddNoteForm() {
            document.getElementById('addNoteForm').style.display = 'none';
            document.querySelector('textarea[name="note_content"]').value = '';
        }
        
        // Print functionality
        window.addEventListener('beforeprint', function() {
            // Show all tab contents for printing
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'block';
            });

            // Hide sidebar for printing
            const sidebar = document.querySelector('.patient-sidebar');
            if (sidebar) sidebar.style.display = 'none';

            // Adjust main content for full width
            const container = document.querySelector('.patient-file-container');
            if (container) container.style.gridTemplateColumns = '1fr';
        });

        window.addEventListener('afterprint', function() {
            // Restore tab functionality
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'none';
            });

            const activeTab = document.querySelector('.tab-content.active');
            if (activeTab) activeTab.style.display = 'block';

            // Restore sidebar
            const sidebar = document.querySelector('.patient-sidebar');
            if (sidebar) sidebar.style.display = 'block';

            // Restore grid layout
            const container = document.querySelector('.patient-file-container');
            if (container) container.style.gridTemplateColumns = '';
        });
        
        // Auto-refresh balance if payment window is opened
        window.addEventListener('focus', function() {
            // Check if we returned from a payment page
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('payment_added')) {
                // Refresh the page to show updated balance
                window.location.reload();
            }
        });
    </script>
</body>
</html>