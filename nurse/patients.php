<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin(['nurse', 'doctor']);

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// إضافة مريض جديد
if ($_POST && $action === 'add') {
    try {
        $pdo->beginTransaction();

        // Check if date_of_birth column exists
        $has_birth_date_column = true;
        try {
            $pdo->query("SELECT date_of_birth FROM patients LIMIT 1");
        } catch (PDOException $e) {
            $has_birth_date_column = false;
        }

        // Check if next_birthday_followup column exists
        $has_birthday_followup_column = true;
        try {
            $pdo->query("SELECT next_birthday_followup FROM patients LIMIT 1");
        } catch (PDOException $e) {
            $has_birthday_followup_column = false;
        }

        if ($has_birth_date_column && $has_birthday_followup_column) {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, date_of_birth, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, next_birthday_followup, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        } elseif ($has_birth_date_column) {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, date_of_birth, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        }

        // Handle date of birth and calculate next birthday followup
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $next_birthday_followup = null;

        if ($date_of_birth && $has_birth_date_column) {
            // Calculate next birthday
            $birth_date = new DateTime($date_of_birth);
            $current_year = date('Y');
            $next_birthday = new DateTime($current_year . '-' . $birth_date->format('m-d'));

            // If birthday has passed this year, set to next year
            if ($next_birthday < new DateTime()) {
                $next_birthday->modify('+1 year');
            }
            $next_birthday_followup = $next_birthday->format('Y-m-d');
        }

        // Handle optional age field
        $age = !empty($_POST['age']) ? $_POST['age'] : null;

        if ($has_birth_date_column && $has_birthday_followup_column) {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $date_of_birth,
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? '',
                $next_birthday_followup
            ]);
        } elseif ($has_birth_date_column) {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $date_of_birth,
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? ''
            ]);
        } else {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? ''
            ]);
        }

        if ($result) {
            $patient_id = $pdo->lastInsertId();

            // Create birthday follow-up if date of birth is provided
            if ($date_of_birth && $next_birthday_followup) {
                createBirthdayFollowup($pdo, $patient_id, $next_birthday_followup);
            }

            $pdo->commit();

            // الطبيب ينتقل مباشرة إلى ملف المريض الجديد (لبدء علاج مثلاً)
            if ($_SESSION['user_role'] === 'doctor') {
                $_SESSION['patient_profile_success'] = "تم إضافة المريض بنجاح";
                header('Location: ../doctor/patient_profile.php?id=' . (int)$patient_id);
                exit;
            }

            $success_message = "تم إضافة المريض بنجاح";
            $action = ''; // إخفاء النموذج
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_message = "خطأ في إضافة المريض: " . $e->getMessage();
    }
}

// Function to create birthday follow-up
function createBirthdayFollowup($pdo, $patient_id, $followup_date) {
    try {
        // Check if follow_ups table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'follow_ups'");
        if ($stmt->rowCount() == 0) {
            return; // Table doesn't exist yet
        }

        $stmt = $pdo->prepare("
            INSERT INTO follow_ups (patient_id, follow_up_type, follow_up_date, follow_up_reason, priority, created_by)
            VALUES (?, 'birthday', ?, 'متابعة عيد الميلاد السنوية - فحص وقائي', 'normal', ?)
        ");
        $stmt->execute([
            $patient_id,
            $followup_date,
            $_SESSION['user_id']
        ]);
    } catch (PDOException $e) {
        // Log error but don't fail the patient creation
        error_log("Failed to create birthday follow-up: " . $e->getMessage());
    }
}

// البحث والفلترة
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// بناء الاستعلام
$where_conditions = ["p.status != 'deleted'"];
$params = [];

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'new') {
    $where_conditions[] = "DATE(p.registration_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter === 'active') {
    $where_conditions[] = "p.last_visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filter === 'followup') {
    $where_conditions[] = "p.last_visit_date <= DATE_SUB(NOW(), INTERVAL 90 DAY)";
}

$where_clause = implode(' AND ', $where_conditions);

// جلب المرضى مع الترقيم
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $where_clause");
    $count_stmt->execute($params);
    $total_patients = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
               (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) as last_visit_date,
               (SELECT COUNT(*) FROM appointments WHERE patient_id = p.id) as total_visits
        FROM patients p 
        WHERE $where_clause
        ORDER BY p.registration_date DESC 
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_patients / $per_page);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patients = [];
    $total_patients = 0;
    $total_pages = 0;
}

// إحصائيات المرضى
try {
    // المرضى الجدد هذا الأسبوع
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(registration_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $new_patients_count = $stmt->fetchColumn();
    
    // المرضى النشطين (زاروا خلال الشهر الماضي)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM patients p 
        WHERE EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND DATE(a.appointment_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY))
    ");
    $active_patients_count = $stmt->fetchColumn();
    
    // المرضى بحاجة متابعة (لم يزوروا منذ 3 شهور)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM patients p 
        WHERE (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) <= DATE_SUB(NOW(), INTERVAL 90 DAY)
        OR (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) IS NULL
    ");
    $followup_patients_count = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $new_patients_count = $active_patients_count = $followup_patients_count = 0;
}

// Set page variables for header
$pageTitle = 'إدارة المرضى';
$pageIcon = 'fas fa-users';
$pageSubtitle = 'إجمالي المرضى: ' . number_format($total_patients ?? 0);
$currentPage = 'patients';

// إغلاق نموذج الإضافة: الطبيب يعود إلى قائمة مرضاه
$form_close_url = $_SESSION['user_role'] === 'doctor' ? '../doctor/patients.php' : '?';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المرضى - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hover-scale:hover { transform: scale(1.02); transition: transform 0.2s; }
        .new-patient { border-right: 4px solid #3b82f6; }
        .urgent-patient { border-right: 4px solid #f59e0b; }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/role_header.php'; ?>

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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى جدد</p>
                        <p class="text-3xl font-bold text-blue-600"><?= $new_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">هذا الأسبوع</p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=new" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        عرض المرضى الجدد <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى نشطين</p>
                        <p class="text-3xl font-bold text-green-600"><?= $active_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">زاروا هذا الشهر</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-heartbeat text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=active" class="text-green-600 hover:text-green-800 text-sm font-medium">
                        عرض المرضى النشطين <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">يحتاجون متابعة</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $followup_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">لم يزوروا منذ 3 شهور</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=followup" class="text-yellow-600 hover:text-yellow-800 text-sm font-medium">
                        عرض المرضى <i class="fas fa-arrow-left mr-1"></i>
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
                                   placeholder="البحث بالاسم أو رقم الهاتف..." 
                                   class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <?php if ($filter): ?>
                                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-4">
                    <a href="?action=add" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-user-plus ml-2"></i>
                        إضافة مريض جديد
                    </a>
 
                </div>
            </div>
        </div>

        <!-- Add Patient Form -->
        <?php if ($action === 'add'): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-user-plus text-green-600 ml-2"></i>
                        إضافة مريض جديد
                    </h3>
                    <a href="<?= $form_close_url ?>" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add">
                    
                    <!-- Basic Information -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-blue-800 mb-4">
                            <i class="fas fa-user ml-2"></i>
                            المعلومات الأساسية
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الاسم الكامل *</label>
                                <input type="text" name="name" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="الاسم الكامل">
                            </div>
                            
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">رقم الهاتف *</label>
                                <input type="tel" name="phone" required pattern="09[0-9]{8}"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="09xxxxxxxx">
                            </div>
                            
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">العمر</label>
                                <input type="number" name="age" id="ageInput" min="1" max="150"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="العمر بالسنوات (اختياري)">
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">
                                    تاريخ الميلاد
                                    <span class="text-sm text-green-600">(لإنشاء متابعة سنوية)</span>
                                </label>
                                <input type="date" name="date_of_birth" id="dateOfBirth"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">سيتم إنشاء تذكير سنوي لعيد الميلاد للفحص الوقائي</p>
                            </div>

                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الجنس *</label>
                                <select name="gender" required 
                                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                    <option value="">اختر الجنس</option>
                                    <option value="male">ذكر</option>
                                    <option value="female">أنثى</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-gray-700 font-semibold mb-2">العنوان</label>
                            <textarea name="address" rows="2"
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                      placeholder="العنوان الكامل"></textarea>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">هاتف الطوارئ</label>
                                <input type="tel" name="emergency_contact" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="رقم هاتف أحد الأقارب">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Medical Information -->
                    <div class="bg-red-50 border border-red-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-red-800 mb-4">
                            <i class="fas fa-heartbeat ml-2"></i>
                            المعلومات الطبية
                        </h4>
                        
                        <div class="space-y-6">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">التاريخ المرضي (أمراض مزمنة - الأدوية - الحساسية - عمليات جراحية سابقة):</label>
                                <textarea name="medical_history" rows="3"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                          placeholder="أي أمراض مزمنة أو عمليات سابقة..."></textarea>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
 
                                 
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex space-x-4 space-x-reverse pt-4">
                        <button type="submit" 
                                class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-save ml-2"></i>
                            إضافة المريض
                        </button>
                        <a href="<?= $form_close_url ?>" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Patients List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list ml-2"></i>
                        قائمة المرضى
                    </h3>
                    <span class="text-sm text-gray-600">
                        عرض <?= count($patients) ?> من أصل <?= number_format($total_patients) ?> مريض
                    </span>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($patients)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-users text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد مرضى</h3>
                        <p class="text-gray-600 mb-6">
                            <?php if ($search || $filter): ?>
                                لم يتم العثور على مرضى تطابق معايير البحث.
                            <?php else: ?>
                                لم يتم إضافة أي مرضى بعد.
                            <?php endif; ?>
                        </p>
                        <a href="?action=add" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-user-plus ml-1"></i>
                            إضافة أول مريض
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                        $is_new = (strtotime($patient['registration_date']) > strtotime('-7 days'));
                        $needs_followup = !$patient['last_visit_date'] || strtotime($patient['last_visit_date']) < strtotime('-90 days');
                        $card_class = $is_new ? 'new-patient' : ($needs_followup ? 'urgent-patient' : '');
                        ?>
                        <div class="patient-card p-6 hover:bg-gray-50 <?= $card_class ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-user text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                <?= htmlspecialchars($patient['name']) ?>
                                                <?php if ($is_new): ?>
                                                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-2">جديد</span>
                                                <?php endif; ?>
                                                <?php if ($needs_followup): ?>
                                                    <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full mr-2">يحتاج متابعة</span>
                                                <?php endif; ?>
                                            </h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-phone ml-1"></i>
                                                <a href="tel:<?= $patient['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4">
                                                    <?= $patient['phone'] ?>
                                                </a>
                                                <i class="fas fa-birthday-cake ml-1"></i>
                                                <span class="ml-4"><?= $patient['age'] ?> سنة</span>
                                                <i class="fas fa-<?= $patient['gender'] === 'male' ? 'mars' : 'venus' ?> ml-1"></i>
                                                <span><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-calendar-plus text-blue-500 ml-1"></i>
                                            <strong>تاريخ التسجيل:</strong>
                                            <?= date('d/m/Y', strtotime($patient['registration_date'])) ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-calendar-check text-green-500 ml-1"></i>
                                            <strong>آخر زيارة:</strong>
                                            <?= $patient['last_visit_date'] ? date('d/m/Y', strtotime($patient['last_visit_date'])) : 'لم يزر بعد' ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-chart-line text-purple-500 ml-1"></i>
                                            <strong>عدد الزيارات:</strong>
                                            <?= $patient['total_visits'] ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($patient['medical_history'] || $patient['allergies']): ?>
                                        <div class="mt-4 p-3 bg-red-50 border-r-4 border-red-300 rounded">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-triangle text-red-600 mt-0.5 ml-2"></i>
                                                <div class="text-sm">
                                                    <?php if ($patient['medical_history']): ?>
                                                        <div class="text-red-800">
                                                            <strong>التاريخ المرضي (أمراض مزمنة - الأدوية - الحساسية - عمليات جراحية سابقة):</strong>
                                                            <?= htmlspecialchars($patient['medical_history']) ?>
                                                        </div>
                                                    <?php endif; ?>
 
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 mr-4">
                                    <a href="patient_details.php?id=<?= $patient['id'] ?>" 
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-eye ml-1"></i>
                                        عرض التفاصيل
                                    </a>
                                    
                                    <a href="appointments.php?action=add&patient_id=<?= $patient['id'] ?>" 
                                       class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-calendar-plus ml-1"></i>
                                        حجز موعد
                                    </a>
                                    
                                    <a href="waiting_list.php?action=add&patient_id=<?= $patient['id'] ?>" 
                                       class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-clock ml-1"></i>
                                        إضافة للانتظار
                                    </a>
                                    
                                    <a href="?action=edit&id=<?= $patient['id'] ?>" 
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-edit ml-1"></i>
                                        تعديل
                                    </a>
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
                            <span class="bg-green-500 text-white px-4 py-2 rounded-lg font-medium">
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
        
        // Phone number formatting
        const phoneInputs = document.querySelectorAll('input[type="tel"]');
        phoneInputs.forEach(input => {
            input.addEventListener('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) value = value.substr(0, 10);
                this.value = value;
            });
        });
        
        // Age validation
        const ageInput = document.querySelector('input[name="age"]');
        ageInput?.addEventListener('input', function() {
            if (this.value < 1) this.value = 1;
            if (this.value > 150) this.value = 150;
        });

        // Auto-calculate age from date of birth
        const dateOfBirthInput = document.getElementById('dateOfBirth');
        const ageInputField = document.getElementById('ageInput');

        if (dateOfBirthInput && ageInputField) {
            dateOfBirthInput.addEventListener('change', function() {
                if (this.value) {
                    const birthDate = new Date(this.value);
                    const today = new Date();

                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();

                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }

                    if (age >= 0 && age <= 150) {
                        ageInputField.value = age;
                    }
                }
            });

            // Also update date of birth when age is changed manually
            ageInputField.addEventListener('input', function() {
                if (this.value && this.value > 0 && this.value <= 150) {
                    const currentYear = new Date().getFullYear();
                    const estimatedBirthYear = currentYear - parseInt(this.value);

                    // Only set if date of birth is empty
                    if (!dateOfBirthInput.value) {
                        dateOfBirthInput.value = `${estimatedBirthYear}-01-01`;
                    }
                }
            });
        }

        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const name = this.querySelector('input[name="name"]').value.trim();
            const phone = this.querySelector('input[name="phone"]').value.trim();
            const age = this.querySelector('input[name="age"]').value;
            const gender = this.querySelector('select[name="gender"]').value;

            if (!name || !phone || !gender) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                return;
            }

            if (!/^09[0-9]{8}$/.test(phone)) {
                e.preventDefault();
                alert('رقم الهاتف غير صحيح. يجب أن يبدأ بـ 09 ويحتوي على 10 أرقام');
                return;
            }

            // Validate age only if provided
            if (age && (age < 1 || age > 150)) {
                e.preventDefault();
                alert('العمر يجب أن يكون بين 1 و 150 سنة');
                return;
            }
        });
        
        // Auto-focus on search input
        if (!window.location.search.includes('action=add')) {
            searchInput?.focus();
        }
    </script>
</body>
</html>