 <?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';
$doctor_id = $_SESSION['user_id'];

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

// معالجة تحديث مراحل العلاج
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_stages') {
    try {
        $treatment_id = $_POST['treatment_id'];
        $treatment_stages_data = $_POST['treatment_stages'];

        $stmt = $pdo->prepare("
            UPDATE treatments
            SET treatment_stages = ?, updated_at = NOW()
            WHERE id = ? AND doctor_id = ?
        ");
        $result = $stmt->execute([$treatment_stages_data, $treatment_id, $doctor_id]);

        if ($result) {
            echo json_encode(['status' => 'success', 'message' => 'تم تحديث مراحل العلاج بنجاح']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل في تحديث مراحل العلاج']);
        }
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
        exit;
    }
}

// معالجة إكمال العلاج
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'complete_treatment') {
    try {
        $treatment_id = $_POST['treatment_id'];
        $completion_notes = $_POST['completion_notes'] ?? '';

        // Check which columns exist
        $columns_exist = [];
        $test_columns = ['status', 'completed_at', 'completion_notes'];

        foreach ($test_columns as $col) {
            try {
                $pdo->query("SELECT $col FROM treatments LIMIT 1");
                $columns_exist[$col] = true;
            } catch (PDOException $e) {
                $columns_exist[$col] = false;
            }
        }

        // Build UPDATE query based on available columns
        $update_parts = [];
        $params = [];

        if ($columns_exist['status']) {
            $update_parts[] = "status = 'completed'";
        }
        if ($columns_exist['completed_at']) {
            $update_parts[] = "completed_at = NOW()";
        }
        if ($columns_exist['completion_notes']) {
            $update_parts[] = "completion_notes = ?";
            $params[] = $completion_notes;
        }

        if (empty($update_parts)) {
            // If no completion columns exist, just update a timestamp or notes field that exists
            $update_parts[] = "updated_at = NOW()";
        }

        $params[] = $treatment_id;
        $params[] = $doctor_id;

        $sql = "UPDATE treatments SET " . implode(', ', $update_parts) . " WHERE id = ? AND doctor_id = ?";
        $stmt = $pdo->prepare($sql);

        $result = $stmt->execute($params);

        if ($result) {
            $success_message = "تم إكمال العلاج بنجاح";

            // تسجيل النشاط
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, table_name, record_id, description)
                    VALUES (?, 'complete_treatment', 'treatments', ?, ?)
                ");
                $stmt->execute([$doctor_id, $treatment_id, "تم إكمال العلاج للمريض"]);
            } catch (PDOException $e) {
                // تجاهل خطأ activity_log إذا لم يكن موجود
            }

            $action = ''; // إخفاء النموذج
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إكمال العلاج: " . $e->getMessage();
    }
}

// إضافة علاج جديد
if ($_POST && $action === 'add') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO treatments (patient_id, appointment_id, treatment_date, treatment_type, 
                                  symptoms, diagnosis, treatment_details, medications, cost, 
                                  next_appointment_date, notes, doctor_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Handle appointment_id properly - convert empty string to null
        $appointment_id = !empty($_POST['appointment_id']) ? $_POST['appointment_id'] : null;
        
        $result = $stmt->execute([
            $_POST['patient_id'],
            $appointment_id,
            $_POST['treatment_date'],
            $_POST['treatment_type'],
            $_POST['symptoms'] ?? '',
            $_POST['diagnosis'],
            $_POST['treatment_details'],
            $_POST['medications'] ?? '',
            $_POST['cost'] ?? null,
            $_POST['next_appointment_date'] ?? null,
            $_POST['notes'] ?? '',
            $doctor_id
        ]);
        
        if ($result) {
            $treatment_id = $pdo->lastInsertId();
            
            // تحديث حالة الموعد إذا كان مرتبطاً بموعد
            if (!empty($_POST['appointment_id'])) {
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', doctor_entry_time = NOW() WHERE id = ?");
                $stmt->execute([$_POST['appointment_id']]);
            }
            
            // تحديث تاريخ آخر زيارة للمريض
            $stmt = $pdo->prepare("UPDATE patients SET last_visit_date = ? WHERE id = ?");
            $stmt->execute([$_POST['treatment_date'], $_POST['patient_id']]);
            
            // إزالة المريض من قائمة الانتظار إذا كان موجوداً
            $stmt = $pdo->prepare("DELETE FROM waiting_list WHERE patient_id = ? AND status = 'waiting'");
            $stmt->execute([$_POST['patient_id']]);
            
            $success_message = "تم إضافة العلاج بنجاح";
            $action = ''; // إخفاء النموذج
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إضافة العلاج: " . $e->getMessage();
    }
}

// تحديث علاج
if ($_POST && $action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            UPDATE treatments SET
                treatment_type = ?, symptoms = ?, diagnosis = ?, treatment_details = ?,
                medications = ?, cost = ?, next_appointment_date = ?, notes = ?, updated_at = NOW()
            WHERE id = ? AND doctor_id = ?
        ");

        $result = $stmt->execute([
            $_POST['treatment_type'],
            $_POST['symptoms'] ?? '',
            $_POST['diagnosis'],
            $_POST['treatment_details'],
            $_POST['medications'] ?? '',
            $_POST['cost'] ?? null,
            $_POST['next_appointment_date'] ?? null,
            $_POST['notes'] ?? '',
            $_GET['id'],
            $doctor_id
        ]);

        if ($result) {
            // تحديث حالة الدفع إذا تم تغيير التكلفة
            if (function_exists('updateTreatmentPaymentStatus')) {
                updateTreatmentPaymentStatus($_GET['id']);
            }

            $success_message = "تم تحديث العلاج بنجاح";
            $action = '';
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في تحديث العلاج: " . $e->getMessage();
    }
}


// البحث والفلترة
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// بناء الاستعلام
$where_conditions = ["t.doctor_id = ?"];
$params = [$doctor_id];

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR t.diagnosis LIKE ? OR t.treatment_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'recent') {
    $where_conditions[] = "DATE(t.treatment_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filter === 'unpaid') {
    $where_conditions[] = "t.payment_status = 'unpaid' AND t.cost > 0";
} elseif ($filter === 'followup') {
    $where_conditions[] = "t.next_appointment_date IS NOT NULL AND t.next_appointment_date >= CURDATE()";
} elseif ($filter === 'today') {
    $where_conditions[] = "DATE(t.treatment_date) = CURDATE()";
} elseif ($filter === 'completed') {
    $where_conditions[] = "(t.status = 'completed' OR t.completed_at IS NOT NULL OR (t.completion_notes IS NOT NULL AND t.completion_notes != ''))
                          AND (t.treatment_stages IS NULL OR t.treatment_stages = '' OR t.treatment_stages = '[]' OR
                               JSON_EXTRACT(t.treatment_stages, '$[*].completed') IS NULL OR
                               JSON_CONTAINS(JSON_EXTRACT(t.treatment_stages, '$[*].completed'), 'false') = 0)";
} elseif ($filter === 'in_progress') {
    $where_conditions[] = "NOT ((t.status = 'completed' OR t.completed_at IS NOT NULL OR (t.completion_notes IS NOT NULL AND t.completion_notes != ''))
                              AND (t.treatment_stages IS NULL OR t.treatment_stages = '' OR t.treatment_stages = '[]' OR
                                   JSON_EXTRACT(t.treatment_stages, '$[*].completed') IS NULL OR
                                   JSON_CONTAINS(JSON_EXTRACT(t.treatment_stages, '$[*].completed'), 'false') = 0))";
}

$where_clause = implode(' AND ', $where_conditions);

// جلب العلاجات
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM treatments t 
        JOIN patients p ON t.patient_id = p.id 
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_treatments = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT t.*, p.name as patient_name, p.phone, p.age, p.gender,
               p.medical_history, p.allergies,
               a.appointment_time,
               COALESCE(SUM(pay.amount), 0) as total_paid,
               CASE
                   WHEN (t.status = 'completed' OR t.completed_at IS NOT NULL OR (t.completion_notes IS NOT NULL AND t.completion_notes != ''))
                        AND (t.treatment_stages IS NULL OR t.treatment_stages = '' OR t.treatment_stages = '[]' OR
                             JSON_EXTRACT(t.treatment_stages, '$[*].completed') IS NULL OR
                             JSON_CONTAINS(JSON_EXTRACT(t.treatment_stages, '$[*].completed'), 'false') = 0)
                   THEN 'completed'
                   ELSE 'in_progress'
               END as treatment_status
        FROM treatments t
        JOIN patients p ON t.patient_id = p.id
        LEFT JOIN appointments a ON t.appointment_id = a.id
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE $where_clause
        GROUP BY t.id
        ORDER BY t.treatment_date DESC, t.created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_treatments / $per_page);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $treatments = [];
    $total_treatments = 0;
    $total_pages = 0;
}

// إحصائيات العلاجات
try {
    // العلاجات هذا الأسبوع
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM treatments 
        WHERE doctor_id = ? AND treatment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$doctor_id]);
    $this_week_count = $stmt->fetchColumn();
    
    // العلاجات غير المدفوعة
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM treatments 
        WHERE doctor_id = ? AND payment_status = 'unpaid' AND cost > 0
    ");
    $stmt->execute([$doctor_id]);
    $unpaid_count = $stmt->fetchColumn();
    
    // المواعيد المجدولة للمتابعة
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM treatments
        WHERE doctor_id = ? AND next_appointment_date IS NOT NULL
        AND next_appointment_date >= CURDATE()
    ");
    $stmt->execute([$doctor_id]);
    $followup_count = $stmt->fetchColumn();

    // العلاجات المكتملة (متوافق مع الجداول القديمة)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM treatments
            WHERE doctor_id = ? AND status = 'completed'
        ");
        $stmt->execute([$doctor_id]);
        $completed_count = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // إذا لم يتم إضافة عمود الحالة بعد
        $completed_count = 0;
    }
    
} catch (PDOException $e) {
    $this_week_count = $unpaid_count = $followup_count = $completed_count = 0;
}

// جلب قائمة المرضى والمواعيد للنموذج
if ($action === 'add') {
    try {
        // المرضى النشطين
        $patients_stmt = $pdo->query("SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name");
        $patients_list = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // المواعيد اليوم
        $appointments_stmt = $pdo->prepare("
            SELECT a.id, a.appointment_time, p.name as patient_name, p.id as patient_id
            FROM appointments a 
            JOIN patients p ON a.patient_id = p.id 
            WHERE DATE(a.appointment_date) = CURDATE() 
            AND a.status IN ('scheduled', 'confirmed')
            AND NOT EXISTS (SELECT 1 FROM treatments t WHERE t.appointment_id = a.id)
            ORDER BY a.appointment_time
        ");
        $appointments_stmt->execute();
        $appointments_list = $appointments_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $patients_list = [];
        $appointments_list = [];
    }
}

// جلب بيانات العلاج للتعديل
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, p.name as patient_name, p.phone
            FROM treatments t
            JOIN patients p ON t.patient_id = p.id
            WHERE t.id = ? AND t.doctor_id = ?
        ");
        $stmt->execute([$_GET['id'], $doctor_id]);
        $treatment_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$treatment_to_edit) {
            header('Location: treatments.php');
            exit;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في جلب بيانات العلاج: " . $e->getMessage();
        $treatment_to_edit = null;
    }
}

// جلب بيانات العلاج للإكمال
$treatment_to_complete = null;
if ($action === 'complete' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, p.name as patient_name, p.phone, p.age, p.gender,
                   CASE
                       WHEN (t.status = 'completed' OR t.completed_at IS NOT NULL OR (t.completion_notes IS NOT NULL AND t.completion_notes != ''))
                            AND (t.treatment_stages IS NULL OR t.treatment_stages = '' OR t.treatment_stages = '[]' OR
                                 JSON_EXTRACT(t.treatment_stages, '$[*].completed') IS NULL OR
                                 JSON_CONTAINS(JSON_EXTRACT(t.treatment_stages, '$[*].completed'), 'false') = 0)
                       THEN 'completed'
                       ELSE 'in_progress'
                   END as treatment_status
            FROM treatments t
            JOIN patients p ON t.patient_id = p.id
            WHERE t.id = ? AND t.doctor_id = ?
        ");
        $stmt->execute([$_GET['id'], $doctor_id]);
        $treatment_to_complete = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$treatment_to_complete) {
            $error_message = "العلاج غير موجود";
            $action = '';
        } else {
            // Check if treatment is already completed using database status
            $is_completed = ($treatment_to_complete['treatment_status'] === 'completed');

            if ($is_completed) {
                $error_message = "تم إكمال هذا العلاج مسبقاً";
                $action = '';
                $treatment_to_complete = null;
            } else {
                // If treatment stages are empty or not set, try to load default stages for this treatment type
                if (empty($treatment_to_complete['treatment_stages']) || $treatment_to_complete['treatment_stages'] === '[]') {
                    try {
                        $stages_stmt = $pdo->prepare("
                            SELECT stage_order, title_ar, description_ar, duration_ar
                            FROM treatment_stages
                            WHERE treatment_type_code = ? AND is_active = TRUE
                            ORDER BY stage_order
                        ");
                        $stages_stmt->execute([$treatment_to_complete['treatment_type']]);
                        $default_stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($default_stages)) {
                            // Convert to the format expected by the treatment stages system
                            $formatted_stages = [];
                            foreach ($default_stages as $stage) {
                                $formatted_stages[] = [
                                    'title' => $stage['title_ar'],
                                    'description' => $stage['description_ar'],
                                    'duration' => $stage['duration_ar'],
                                    'completed' => false,
                                    'completedDate' => null,
                                    'notes' => ''
                                ];
                            }

                            // Update the treatment with default stages
                            $update_stages_stmt = $pdo->prepare("
                                UPDATE treatments
                                SET treatment_stages = ?
                                WHERE id = ? AND doctor_id = ?
                            ");
                            $stages_json = json_encode($formatted_stages, JSON_UNESCAPED_UNICODE);
                            $update_stages_stmt->execute([$stages_json, $treatment_to_complete['id'], $doctor_id]);

                            // Update the local treatment data
                            $treatment_to_complete['treatment_stages'] = $stages_json;
                        }
                    } catch (PDOException $e) {
                        // If stages loading fails, continue without stages
                        error_log("Failed to load treatment stages: " . $e->getMessage());
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في جلب بيانات العلاج: " . $e->getMessage();
        $treatment_to_complete = null;
        $action = '';
    }
}

// معالجة المعاملات المحددة مسبقاً
$preselected_appointment = $_GET['appointment_id'] ?? null;
$preselected_patient = $_GET['patient_id'] ?? null;

// Header configuration
$pageTitle = 'إدارة العلاجات';
$pageIcon = 'fas fa-file-medical';
$pageSubtitle = 'إجمالي العلاجات: ' . number_format($total_treatments);
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
        .treatment-card { transition: all 0.3s ease; border-right: 4px solid transparent; }
        .treatment-card:hover { border-right-color: #3b82f6; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .status-paid { border-right-color: #10b981 !important; }
        .status-unpaid { border-right-color: #ef4444 !important; }
        .status-partial { border-right-color: #f59e0b !important; }
        .medical-alert { background: linear-gradient(45deg, #fee2e2, #fef2f2); }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-1"></i>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">علاجات هذا الأسبوع</p>
                        <p class="text-3xl font-bold text-green-600"><?= $this_week_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">مكتملة</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-tooth text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=recent" class="text-green-600 hover:text-green-800 text-sm font-medium">
                        عرض العلاجات الحديثة <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">علاجات غير مدفوعة</p>
                        <p class="text-3xl font-bold text-red-600"><?= $unpaid_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">تحتاج متابعة</p>
                    </div>
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=unpaid" class="text-red-600 hover:text-red-800 text-sm font-medium">
                        عرض المستحقات <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مواعيد متابعة</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $followup_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">مجدولة</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-calendar-check text-yellow-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=followup" class="text-yellow-600 hover:text-yellow-800 text-sm font-medium">
                        عرض المتابعات <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">علاجات مكتملة</p>
                        <p class="text-3xl font-bold text-green-600"><?= $completed_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">إجمالي</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=completed" class="text-green-600 hover:text-green-800 text-sm font-medium">
                        عرض المكتملة <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Search and Controls -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex-1 flex flex-col sm:flex-row gap-4">
                    <!-- Search Form -->
                    <form method="GET" class="flex-1">
                        <div class="relative">
                            <input type="text" 
                                   name="search" 
                                   value="<?= htmlspecialchars($search) ?>"
                                   placeholder="البحث بالمريض أو التشخيص أو نوع العلاج..." 
                                   class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <?php if ($filter): ?>
                                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <!-- Filter Buttons -->
                    <div class="flex flex-wrap gap-2">
                        <a href="?" class="<?= !$filter ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            الكل
                        </a>
                        <a href="?filter=today<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'today' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            اليوم
                        </a>
                        <a href="?filter=recent<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'recent' ? 'bg-purple-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            حديثة
                        </a>
                        <a href="?filter=unpaid<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'unpaid' ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            غير مدفوعة
                        </a>
                        <a href="?filter=followup<?= $search ? '&search=' . urlencode($search) : '' ?>"
                           class="<?= $filter === 'followup' ? 'bg-yellow-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            متابعة
                        </a>
                        <a href="?filter=completed<?= $search ? '&search=' . urlencode($search) : '' ?>"
                           class="<?= $filter === 'completed' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            مكتملة
                        </a>
                        <a href="?filter=in_progress<?= $search ? '&search=' . urlencode($search) : '' ?>"
                           class="<?= $filter === 'in_progress' ? 'bg-yellow-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            جارية
                        </a>
                    </div>
                </div>
                
<!-- Action Buttons - محدث -->
<div class="flex gap-4">
    <a href="treatment_new.php<?= $preselected_appointment ? '?appointment_id=' . $preselected_appointment : '' ?><?= $preselected_patient ? ($preselected_appointment ? '&' : '?') . 'patient_id=' . $preselected_patient : '' ?>" 
       class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition flex items-center">
        <i class="fas fa-plus ml-2"></i>
        إضافة علاج جديد
    </a>
    <button onclick="window.print()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
        <i class="fas fa-print"></i>
    </button>
</div>
            </div>
        </div>


        <!-- Add/Edit Treatment Form -->
        <?php if ($action === 'edit'): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-edit text-blue-600 ml-2"></i>
                        تعديل العلاج
                    </h3>
                    <a href="?" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="<?= $action ?>">
                    
                    <?php if ($action === 'add'): ?>
                    <!-- Patient and Appointment Selection -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Appointment Selection -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">الموعد (اختياري)</label>
                            <select name="appointment_id" id="appointmentSelect"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    onchange="selectAppointmentPatient()">
                                <option value="">اختر موعد من اليوم...</option>
                                <?php foreach ($appointments_list as $appointment): ?>
                                    <option value="<?= $appointment['id'] ?>" 
                                            data-patient-id="<?= $appointment['patient_id'] ?>"
                                            <?= $preselected_appointment == $appointment['id'] ? 'selected' : '' ?>>
                                        <?= date('H:i', strtotime($appointment['appointment_time'])) ?> - <?= htmlspecialchars($appointment['patient_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Patient Selection -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">المريض *</label>
                            <select name="patient_id" id="patientSelect" required 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">اختر مريض...</option>
                                <?php foreach ($patients_list as $patient): ?>
                                    <option value="<?= $patient['id'] ?>" <?= $preselected_patient == $patient['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($patient['name']) ?> - <?= $patient['phone'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Treatment Information -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-blue-800 mb-4">
                            <i class="fas fa-tooth ml-2"></i>
                            معلومات العلاج
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Treatment Date -->
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">تاريخ العلاج *</label>
                                <input type="date" name="treatment_date" 
                                       value="<?= $action === 'edit' ? $treatment_to_edit['treatment_date'] : date('Y-m-d') ?>" 
                                       required max="<?= date('Y-m-d') ?>"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            
                            <!-- Treatment Type -->
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                                <select name="treatment_type" required
                                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="">اختر نوع العلاج</option>
                                    <?php foreach ($treatment_types_map as $code => $name): ?>
                                        <option value="<?= htmlspecialchars($code) ?>"
                                                <?= ($action === 'edit' && $treatment_to_edit['treatment_type'] === $code) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Symptoms -->
                        <div class="mt-6">
                            <label class="block text-gray-700 font-semibold mb-2">الأعراض</label>
                            <textarea name="symptoms" rows="2"
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                      placeholder="وصف الأعراض التي يعاني منها المريض..."><?= $action === 'edit' ? htmlspecialchars($treatment_to_edit['symptoms'] ?? '') : '' ?></textarea>
                        </div>
                        
                        <!-- Diagnosis -->
                        <div class="mt-6">
                            <label class="block text-gray-700 font-semibold mb-2">التشخيص *</label>
                            <textarea name="diagnosis" rows="3" required
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                      placeholder="التشخيص الطبي للحالة..."><?= $action === 'edit' ? htmlspecialchars($treatment_to_edit['diagnosis']) : '' ?></textarea>
                        </div>
                        
                        <!-- Treatment Details -->
                        <div class="mt-6">
                            <label class="block text-gray-700 font-semibold mb-2">تفاصيل العلاج *</label>
                            <textarea name="treatment_details" rows="4" required
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                      placeholder="وصف مفصل للإجراءات المتخذة والعلاج المقدم..."><?= $action === 'edit' ? htmlspecialchars($treatment_to_edit['treatment_details']) : '' ?></textarea>
                        </div>
                        
                        <!-- Medications -->
                        <div class="mt-6">
                            <label class="block text-gray-700 font-semibold mb-2">الأدوية الموصوفة</label>
                            <textarea name="medications" rows="3"
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                      placeholder="الأدوية والمضادات الحيوية الموصوفة مع الجرعات..."><?= $action === 'edit' ? htmlspecialchars($treatment_to_edit['medications'] ?? '') : '' ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Financial and Follow-up Information -->
                    <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-green-800 mb-4">
                            <i class="fas fa-money-bill-wave ml-2"></i>
                            معلومات مالية ومتابعة
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Cost -->
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">تكلفة العلاج (ليرة سورية)</label>
                                <input type="number" name="cost" step="0.01" min="0"
                                       value="<?= $action === 'edit' ? $treatment_to_edit['cost'] : '' ?>"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="0.00">
                            </div>
                            
                            <!-- Next Appointment Date -->
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">موعد المتابعة القادم</label>
                                <input type="date" name="next_appointment_date" 
                                       value="<?= $action === 'edit' ? $treatment_to_edit['next_appointment_date'] : '' ?>"
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ملاحظات إضافية</label>
                        <textarea name="notes" rows="3"
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                  placeholder="أي ملاحظات إضافية أو تعليمات للمريض..."><?= $action === 'edit' ? htmlspecialchars($treatment_to_edit['notes'] ?? '') : '' ?></textarea>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex space-x-4 space-x-reverse pt-4">
                        <button type="submit" 
                                class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-save ml-2"></i>
                            <?= $action === 'add' ? 'حفظ العلاج' : 'حفظ التعديلات' ?>
                        </button>
                        <a href="?" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Complete Treatment Form -->
        <?php if ($action === 'complete' && $treatment_to_complete): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-check-circle text-green-600 ml-2"></i>
                        إكمال العلاج
                    </h3>
                    <a href="?" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>

                <!-- Treatment Review -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                    <h4 class="text-lg font-semibold text-blue-800 mb-4">
                        <i class="fas fa-info-circle ml-2"></i>
                        مراجعة بيانات العلاج
                    </h4>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h5 class="font-semibold text-gray-800 mb-3">معلومات المريض</h5>
                            <div class="space-y-2">
                                <p><span class="font-medium">الاسم:</span> <?= htmlspecialchars($treatment_to_complete['patient_name']) ?></p>
                                <p><span class="font-medium">الهاتف:</span> <?= htmlspecialchars($treatment_to_complete['phone']) ?></p>
                                <p><span class="font-medium">العمر:</span> <?= htmlspecialchars($treatment_to_complete['age']) ?> سنة</p>
                                <p><span class="font-medium">الجنس:</span> <?= $treatment_to_complete['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></p>
                            </div>
                        </div>

                        <div>
                            <h5 class="font-semibold text-gray-800 mb-3">تفاصيل العلاج</h5>
                            <div class="space-y-2">
                                <p><span class="font-medium">نوع العلاج:</span> <?= htmlspecialchars($treatment_types_map[$treatment_to_complete['treatment_type']] ?? $treatment_to_complete['treatment_type']) ?></p>
                                <p><span class="font-medium">التاريخ:</span> <?= date('d/m/Y', strtotime($treatment_to_complete['treatment_date'])) ?></p>
                                <?php if ($treatment_to_complete['cost']): ?>
                                <p><span class="font-medium">التكلفة:</span> <?= number_format($treatment_to_complete['cost'], 2) ?> ل.س</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($treatment_to_complete['symptoms'] || $treatment_to_complete['diagnosis'] || $treatment_to_complete['treatment_details']): ?>
                    <div class="mt-6 pt-4 border-t border-blue-200">
                        <h5 class="font-semibold text-gray-800 mb-3">التفاصيل الطبية</h5>
                        <div class="space-y-3">
                            <?php if ($treatment_to_complete['symptoms']): ?>
                            <div>
                                <span class="font-medium">الأعراض:</span>
                                <p class="text-gray-700 mt-1"><?= htmlspecialchars($treatment_to_complete['symptoms']) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($treatment_to_complete['diagnosis']): ?>
                            <div>
                                <span class="font-medium">التشخيص:</span>
                                <p class="text-gray-700 mt-1"><?= htmlspecialchars($treatment_to_complete['diagnosis']) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if ($treatment_to_complete['treatment_details']): ?>
                            <div>
                                <span class="font-medium">تفاصيل العلاج:</span>
                                <p class="text-gray-700 mt-1"><?= htmlspecialchars($treatment_to_complete['treatment_details']) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Treatment Stages Section -->
                <?php if (!empty($treatment_to_complete['treatment_stages']) && $treatment_to_complete['treatment_stages'] !== '[]'): ?>
                <div class="bg-purple-50 border border-purple-200 rounded-lg p-6 mb-6">
                    <h4 class="text-lg font-semibold text-purple-800 mb-4">
                        <i class="fas fa-tasks ml-2"></i>
                        مراحل العلاج
                    </h4>

                    <div id="treatmentStagesDisplay">
                        <!-- سيتم ملء مراحل العلاج هنا بواسطة JavaScript -->
                    </div>

                    <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-600 mt-1 ml-2"></i>
                            <div class="text-sm text-yellow-700">
                                <strong>ملاحظة:</strong> يجب إكمال جميع مراحل العلاج قبل وضع علامة على العلاج كاملاً.
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Completion Form -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                    <h4 class="text-lg font-semibold text-green-800 mb-4">
                        <i class="fas fa-flag-checkered ml-2"></i>
                        الإكمال النهائي
                    </h4>

                    <form method="POST">
                        <input type="hidden" name="action" value="complete_treatment">
                        <input type="hidden" name="treatment_id" value="<?= $treatment_to_complete['id'] ?>">

                        <div class="mb-6">
                            <label class="block text-gray-700 font-semibold mb-2">ملاحظات الإكمال (اختياري)</label>
                            <textarea name="completion_notes" rows="4"
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                      placeholder="أي ملاحظات إضافية حول إكمال العلاج..."></textarea>
                        </div>

                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 ml-2"></i>
                                <div>
                                    <h6 class="font-semibold text-yellow-800">تأكيد الإكمال</h6>
                                    <p class="text-yellow-700 text-sm mt-1">
                                        بالنقر على "إكمال العلاج"، ستؤكد أن العلاج قد تم بنجاح ولا يحتاج لمتابعة إضافية.
                                        هذا الإجراء لا يمكن التراجع عنه.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="flex space-x-4 space-x-reverse pt-4">
                            <button type="submit"
                                    class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                                <i class="fas fa-check-circle ml-2"></i>
                                إكمال العلاج
                            </button>
                            <a href="?"
                               class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                                إلغاء
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Treatments List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list ml-2"></i>
                        قائمة العلاجات
                    </h3>
                    <span class="text-sm text-gray-600">
                        عرض <?= count($treatments) ?> من أصل <?= number_format($total_treatments) ?> علاج
                    </span>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($treatments)): ?>
                    <div class="text-center py-16">
    <i class="fas fa-file-medical text-6xl text-gray-300 mb-4"></i>
    <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد علاجات</h3>
    <p class="text-gray-600 mb-6">
        <?php if ($search || $filter): ?>
            لم يتم العثور على علاجات تطابق معايير البحث.
        <?php else: ?>
            لم يتم تسجيل أي علاجات بعد.
        <?php endif; ?>
    </p>
    <a href="treatment_new.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition">
        <i class="fas fa-plus ml-1"></i>
        إضافة أول علاج
    </a>
</div>
                <?php else: ?>
                    <?php foreach ($treatments as $treatment): ?>
                        <?php
                        $remaining_balance = $treatment['cost'] - $treatment['total_paid'];
                        $payment_status_class = '';
                        if ($treatment['cost'] > 0) {
                            if ($treatment['total_paid'] >= $treatment['cost']) {
                                $payment_status_class = 'status-paid';
                            } elseif ($treatment['total_paid'] > 0) {
                                $payment_status_class = 'status-partial';
                            } else {
                                $payment_status_class = 'status-unpaid';
                            }
                        }
                        ?>
                        <div class="treatment-card p-6 hover:bg-gray-50 <?= $payment_status_class ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-blue-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-tooth text-blue-600"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between">
                                                <h4 class="text-lg font-semibold text-gray-900">
                                                    <?= htmlspecialchars($treatment['patient_name']) ?>
                                                </h4>
                                                <div class="flex items-center">
                                                    <?php
                                                    // استخدام حالة العلاج من قاعدة البيانات
                                                    $is_completed = ($treatment['treatment_status'] === 'completed');

                                                    if ($is_completed):
                                                    ?>
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            <i class="fas fa-check-circle ml-1"></i>
                                                            مكتمل
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                            <i class="fas fa-clock ml-1"></i>
                                                            جاري
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-calendar ml-1"></i>
                                                <span class="ml-4"><?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?></span>
                                                <?php if ($treatment['appointment_time']): ?>
                                                    <i class="fas fa-clock ml-1"></i>
                                                    <span class="ml-4"><?= date('H:i', strtotime($treatment['appointment_time'])) ?></span>
                                                <?php endif; ?>
                                                <i class="fas fa-user ml-1"></i>
                                                <span><?= $treatment['age'] ?> سنة - <?= $treatment['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-stethoscope text-green-500 ml-1"></i>
                                            <strong>نوع العلاج:</strong>
                                            <?= htmlspecialchars($treatment_types_map[$treatment['treatment_type']] ?? $treatment['treatment_type']) ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-search text-purple-500 ml-1"></i>
                                            <strong>التشخيص:</strong>
                                            <?= htmlspecialchars(substr($treatment['diagnosis'], 0, 50)) ?><?= strlen($treatment['diagnosis']) > 50 ? '...' : '' ?>
                                        </div>
                                    </div>

                                    <?php
                                    // عرض تقدم مراحل العلاج
                                    if (!empty($treatment['treatment_stages']) && $treatment['treatment_stages'] !== '[]'):
                                        $stages = json_decode($treatment['treatment_stages'], true);
                                        if (is_array($stages) && count($stages) > 0):
                                            $completed_stages = array_filter($stages, function($stage) { return isset($stage['completed']) && $stage['completed']; });
                                            $total_stages = count($stages);
                                            $completed_count = count($completed_stages);
                                            $progress_percentage = ($total_stages > 0) ? ($completed_count / $total_stages) * 100 : 0;
                                    ?>
                                    <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-medium text-blue-800">
                                                <i class="fas fa-tasks ml-1"></i>
                                                مراحل العلاج
                                            </span>
                                            <span class="text-xs text-blue-600"><?= $completed_count ?> من <?= $total_stages ?></span>
                                        </div>
                                        <div class="w-full bg-blue-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: <?= $progress_percentage ?>%"></div>
                                        </div>
                                        <div class="text-xs text-blue-600 mt-1"><?= round($progress_percentage) ?>% مكتمل</div>
                                    </div>
                                    <?php
                                        endif;
                                    endif;
                                    ?>
                                    
                                    <?php if ($treatment['cost'] > 0): ?>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 text-sm">
                                            <div class="text-gray-600">
                                                <i class="fas fa-money-bill text-blue-500 ml-1"></i>
                                                <strong>التكلفة:</strong>
                                                <span class="font-semibold text-blue-600"><?= number_format($treatment['cost'], 2) ?> ليرة سورية</span>
                                            </div>
                                            <div class="text-gray-600">
                                                <i class="fas fa-check-circle text-green-500 ml-1"></i>
                                                <strong>المدفوع:</strong>
                                                <span class="font-semibold text-green-600"><?= number_format($treatment['total_paid'], 2) ?> ليرة سورية</span>
                                            </div>
                                            <div class="text-gray-600">
                                                <i class="fas fa-exclamation-triangle text-red-500 ml-1"></i>
                                                <strong>المتبقي:</strong>
                                                <span class="font-semibold <?= $remaining_balance > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                                    <?= number_format($remaining_balance, 2) ?> ليرة سورية
                                                </span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Medical Alerts -->
                                    <?php if ($treatment['medical_history'] || $treatment['allergies']): ?>
                                        <div class="mt-4 p-3 medical-alert border-r-4 border-red-300 rounded">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-triangle text-red-600 mt-0.5 ml-2"></i>
                                                <div class="text-sm">
                                                    <?php if ($treatment['medical_history']): ?>
                                                        <div class="text-red-800">
                                                            <strong>التاريخ المرضي:</strong>
                                                            <?= htmlspecialchars(substr($treatment['medical_history'], 0, 100)) ?><?= strlen($treatment['medical_history']) > 100 ? '...' : '' ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($treatment['allergies']): ?>
                                                        <div class="text-red-800 <?= $treatment['medical_history'] ? 'mt-1' : '' ?>">
                                                            <strong>الحساسية:</strong>
                                                            <?= htmlspecialchars($treatment['allergies']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Follow-up Alert -->
                                    <?php if ($treatment['next_appointment_date'] && strtotime($treatment['next_appointment_date']) <= strtotime('+7 days')): ?>
                                        <div class="mt-4 p-3 bg-yellow-50 border-r-4 border-yellow-300 rounded">
                                            <p class="text-sm text-yellow-800">
                                                <i class="fas fa-calendar-check ml-1"></i>
                                                <strong>موعد المتابعة:</strong>
                                                <?= date('d/m/Y', strtotime($treatment['next_appointment_date'])) ?>
                                                <?php if (strtotime($treatment['next_appointment_date']) < strtotime('today')): ?>
                                                    <span class="text-red-600 font-semibold">(متأخر)</span>
                                                <?php elseif (strtotime($treatment['next_appointment_date']) <= strtotime('+3 days')): ?>
                                                    <span class="text-yellow-600 font-semibold">(قريب)</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Completion Info -->
                                    <?php if ($is_completed): ?>
                                        <div class="mt-4 p-3 bg-green-50 border-r-4 border-green-300 rounded">
                                            <div class="text-sm text-green-800">
                                                <div class="flex items-center mb-1">
                                                    <i class="fas fa-check-circle ml-1"></i>
                                                    <strong>تم إكمال العلاج:</strong>
                                                    <span class="mr-2"><?= isset($treatment['completed_at']) && $treatment['completed_at'] ? date('d/m/Y H:i', strtotime($treatment['completed_at'])) : 'غير محدد' ?></span>
                                                </div>
                                                <?php if (isset($treatment['completion_notes']) && $treatment['completion_notes']): ?>
                                                    <div class="mt-1">
                                                        <strong>ملاحظات الإكمال:</strong>
                                                        <?= htmlspecialchars($treatment['completion_notes']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
 <div class="flex flex-col space-y-2 mr-4">
    <!-- زر عرض التفاصيل -->
    <a href="treatment_details.php?id=<?= $treatment['id'] ?>"
       class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
        <i class="fas fa-eye ml-1"></i>
        عرض التفاصيل
    </a>

    <!-- زر إكمال العلاج (للعلاجات الجارية فقط) -->
    <?php if (!$is_completed): ?>
        <a href="treatment_new.php?complete_id=<?= $treatment['id'] ?>"
           class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
            <i class="fas fa-check-circle ml-1"></i>
            إكمال العلاج
        </a>
    <?php endif; ?>

    <!-- زر الملف الشخصي -->
    <a href="patient_profile.php?id=<?= $treatment['patient_id'] ?>" 
       class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
        <i class="fas fa-user-circle ml-1"></i>
        الملف الشخصي
    </a>
    
    <!-- زر علاج جديد للمريض -->
    <a href="treatment_new.php?patient_id=<?= $treatment['patient_id'] ?>"
       class="bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
        <i class="fas fa-plus ml-1"></i>
        علاج جديد
    </a>

    <!-- زر الدفع (إذا كان هناك مبلغ متبقي) -->
    <?php if ($treatment['cost'] > 0 && $remaining_balance > 0): ?>
        <a href="../nurse/patient_balance.php?action=add_payment&patient_id=<?= $treatment['patient_id'] ?>&treatment_id=<?= $treatment['id'] ?>" 
           class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
            <i class="fas fa-money-bill ml-1"></i>
            دفعة
        </a>
    <?php endif; ?>
</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center fade-in">
                <nav class="flex items-center space-x-2 space-x-reverse">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                           class="bg-white border border-gray-300 text-gray-500 hover:bg-gray-50 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="bg-blue-500 text-white px-4 py-2 rounded-lg font-medium">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                               class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg transition">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                           class="bg-white border border-gray-300 text-gray-500 hover:bg-gray-50 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-submit search form on input
        const searchInput = document.querySelector('input[name="search"]');
        let searchTimeout;
        
        searchInput?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 1000);
        });
        
        // Appointment-Patient linking
        function selectAppointmentPatient() {
            const appointmentSelect = document.getElementById('appointmentSelect');
            const patientSelect = document.getElementById('patientSelect');
            
            if (appointmentSelect.value) {
                const selectedOption = appointmentSelect.options[appointmentSelect.selectedIndex];
                const patientId = selectedOption.getAttribute('data-patient-id');
                
                if (patientId) {
                    patientSelect.value = patientId;
                }
            }
        }
        
        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const patientId = this.querySelector('select[name="patient_id"]')?.value;
            const diagnosis = this.querySelector('textarea[name="diagnosis"]')?.value.trim();
            const treatmentDetails = this.querySelector('textarea[name="treatment_details"]')?.value.trim();
            const treatmentType = this.querySelector('select[name="treatment_type"]')?.value;
            
            if (!patientId || !diagnosis || !treatmentDetails || !treatmentType) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                return;
            }
            
            if (diagnosis.length < 10) {
                e.preventDefault();
                alert('يجب أن يكون التشخيص أكثر تفصيلاً (على الأقل 10 أحرف)');
                return;
            }
            
            if (treatmentDetails.length < 20) {
                e.preventDefault();
                alert('يجب أن تكون تفاصيل العلاج أكثر تفصيلاً (على الأقل 20 حرف)');
                return;
            }
        });
        
        // Auto-focus on first input if form is visible
        if (document.querySelector('select[name="patient_id"]')) {
            document.querySelector('select[name="patient_id"]').focus();
        }
        
        // Set initial appointment-patient selection
        <?php if ($preselected_appointment): ?>
        document.addEventListener('DOMContentLoaded', function() {
            selectAppointmentPatient();
        });
        <?php endif; ?>

        // Treatment Stages Management for Completion
        <?php if ($action === 'complete' && $treatment_to_complete && !empty($treatment_to_complete['treatment_stages']) && $treatment_to_complete['treatment_stages'] !== '[]'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Load treatment stages data
            const treatmentStagesData = <?= $treatment_to_complete['treatment_stages'] ?>;
            displayTreatmentStages(treatmentStagesData);
        });

        function displayTreatmentStages(stages) {
            const container = document.getElementById('treatmentStagesDisplay');
            if (!container || !stages || !Array.isArray(stages)) return;

            let html = '<div class="space-y-3">';

            stages.forEach((stage, index) => {
                const isCompleted = stage.completed || false;
                const statusClass = isCompleted ? 'bg-green-100 border-green-200 text-green-800' : 'bg-gray-100 border-gray-200 text-gray-600';
                const iconClass = isCompleted ? 'fas fa-check-circle text-green-600' : 'fas fa-clock text-gray-400';

                html += `
                    <div class="flex items-center justify-between p-3 rounded-lg border ${statusClass}">
                        <div class="flex items-center">
                            <i class="${iconClass} ml-3"></i>
                            <div>
                                <div class="font-medium">${stage.title || 'مرحلة العلاج'}</div>
                                <div class="text-sm opacity-75">${stage.description || ''}</div>
                                ${stage.duration ? `<div class="text-xs opacity-60 mt-1">المدة المتوقعة: ${stage.duration}</div>` : ''}
                            </div>
                        </div>
                        <div class="flex items-center space-x-reverse space-x-2">
                            ${isCompleted ?
                                `<span class="bg-green-500 text-white px-2 py-1 rounded text-xs">مكتمل</span>` :
                                `<button type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs"
                                         onclick="completeStage(${index})">إكمال</button>`
                            }
                        </div>
                    </div>
                `;
            });

            html += '</div>';

            // Add progress summary
            const completedStages = stages.filter(stage => stage.completed).length;
            const totalStages = stages.length;
            const progressPercentage = (completedStages / totalStages) * 100;

            html += `
                <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-blue-800">التقدم الإجمالي</span>
                        <span class="text-xs text-blue-600">${completedStages} من ${totalStages}</span>
                    </div>
                    <div class="w-full bg-blue-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: ${progressPercentage}%"></div>
                    </div>
                    <div class="text-xs text-blue-600 mt-1">${Math.round(progressPercentage)}% مكتمل</div>
                </div>
            `;

            container.innerHTML = html;
        }

        function completeStage(stageIndex) {
            if (confirm('هل تريد إكمال هذه المرحلة؟')) {
                // Update the stage data
                const treatmentStagesData = <?= $treatment_to_complete['treatment_stages'] ?>;
                if (treatmentStagesData[stageIndex]) {
                    treatmentStagesData[stageIndex].completed = true;
                    treatmentStagesData[stageIndex].completedDate = new Date().toISOString().split('T')[0];

                    // Update the display
                    displayTreatmentStages(treatmentStagesData);

                    // Send AJAX request to update the database
                    updateTreatmentStages(<?= $treatment_to_complete['id'] ?>, treatmentStagesData);
                }
            }
        }

        function updateTreatmentStages(treatmentId, stagesData) {
            const formData = new FormData();
            formData.append('action', 'update_stages');
            formData.append('treatment_id', treatmentId);
            formData.append('treatment_stages', JSON.stringify(stagesData));

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                // Optionally show success message or reload
                console.log('Treatment stages updated successfully');
            })
            .catch(error => {
                console.error('Error updating treatment stages:', error);
                alert('حدث خطأ في تحديث مراحل العلاج');
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>