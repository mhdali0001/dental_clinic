<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin(['nurse', 'doctor']);

// Set page variables for header
$pageTitle = 'تفاصيل المريض';
$pageIcon = 'fas fa-user';
$pageSubtitle = '';
$currentPage = 'patients';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// التحقق من وجود معرف المريض
$patient_id = $_GET['id'] ?? 0;
if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// تحديث بيانات المريض
if ($_POST && $action === 'update') {
    try {
        $stmt = $pdo->prepare("
            UPDATE patients SET 
                name = ?, phone = ?, age = ?, gender = ?, address = ?, 
                email = ?, emergency_contact = ?, medical_history = ?, 
                allergies = ?, blood_type = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $_POST['name'],
            $_POST['phone'],
            $_POST['age'],
            $_POST['gender'],
            $_POST['address'] ?? '',
            $_POST['email'] ?? '',
            $_POST['emergency_contact'] ?? '',
            $_POST['medical_history'] ?? '',
            $_POST['allergies'] ?? '',
            $_POST['blood_type'] ?? '',
            $patient_id
        ]);
        
        if ($result) {
            $success_message = "تم تحديث بيانات المريض بنجاح";
            $action = ''; // إخفاء النموذج
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في تحديث البيانات: " . $e->getMessage();
    }
}

// جلب بيانات المريض
try {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Location: patients.php');
        exit;
    }
    // Update page subtitle with patient name
    $pageSubtitle = htmlspecialchars($patient['name']);
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patient = null;
}

// جلب تاريخ المواعيد
try {
    $stmt = $pdo->prepare("
        SELECT *, 
               CASE 
                   WHEN appointment_date = CURDATE() THEN 'اليوم'
                   WHEN appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'غداً'
                   WHEN appointment_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 'أمس'
                   ELSE DATE_FORMAT(appointment_date, '%d/%m/%Y')
               END as formatted_date
        FROM appointments 
        WHERE patient_id = ? 
        ORDER BY appointment_date DESC, appointment_time DESC
        LIMIT 20
    ");
    $stmt->execute([$patient_id]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات المواعيد
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_appointments,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_appointments,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_appointments,
            MAX(appointment_date) as last_visit,
            MIN(appointment_date) as first_visit
        FROM appointments 
        WHERE patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $appointment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $appointments = [];
    $appointment_stats = [
        'total_appointments' => 0,
        'completed_appointments' => 0,
        'cancelled_appointments' => 0,
        'last_visit' => null,
        'first_visit' => null
    ];
}

// جلب معلومات قائمة الانتظار الحالية
try {
    $stmt = $pdo->prepare("
        SELECT * FROM waiting_list 
        WHERE patient_id = ? AND status = 'waiting'
        ORDER BY arrival_time DESC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $current_waiting = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_waiting = null;
}

// حساب العمر بالتفصيل
function calculateDetailedAge($birth_date) {
    if (!$birth_date) return '';
    
    $birth = new DateTime($birth_date);
    $today = new DateTime();
    $diff = $today->diff($birth);
    
    return $diff->y . ' سنة و ' . $diff->m . ' شهر';
}

// حساب مدة التسجيل
function getRegistrationDuration($registration_date) {
    $registration = new DateTime($registration_date);
    $today = new DateTime();
    $diff = $today->diff($registration);
    
    if ($diff->y > 0) {
        return $diff->y . ' سنة';
    } elseif ($diff->m > 0) {
        return $diff->m . ' شهر';
    } else {
        return $diff->d . ' يوم';
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل المريض - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .medical-alert { background: linear-gradient(135deg, #fef2f2 0%, #fef7f7 100%); }
        .patient-card { transition: all 0.3s ease; }
        .patient-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
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

        <?php if (!$patient): ?>
            <div class="bg-white rounded-lg shadow-lg p-16 text-center">
                <i class="fas fa-user-times text-6xl text-gray-300 mb-6"></i>
                <h3 class="text-2xl font-bold text-gray-900 mb-4">المريض غير موجود</h3>
                <p class="text-gray-600 mb-8">لم يتم العثور على المريض المطلوب.</p>
                <a href="patients.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition">
                    العودة لقائمة المرضى
                </a>
            </div>
        <?php else: ?>

        <!-- Patient Header Card -->
        <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center mb-6 lg:mb-0">
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-4 rounded-full">
                        <i class="fas fa-user text-3xl text-white"></i>
                    </div>
                    <div class="mr-6">
                        <h2 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($patient['name']) ?></h2>
                        <div class="flex items-center text-lg text-gray-600 mt-2">
                            <i class="fas fa-phone ml-2"></i>
                            <a href="tel:<?= $patient['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4">
                                <?= $patient['phone'] ?>
                            </a>
                            <i class="fas fa-birthday-cake ml-2"></i>
                            <span class="ml-4"><?= $patient['age'] ?> سنة</span>
                            <i class="fas fa-<?= $patient['gender'] === 'male' ? 'mars' : 'venus' ?> ml-2 text-<?= $patient['gender'] === 'male' ? 'blue' : 'pink' ?>-500"></i>
                            <span><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                        </div>
                        <div class="flex items-center text-sm text-gray-500 mt-2">
                            <i class="fas fa-calendar-plus ml-1"></i>
                            مسجل منذ <?= getRegistrationDuration($patient['registration_date']) ?> 
                            (<?= date('d/m/Y', strtotime($patient['registration_date'])) ?>)
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="appointments.php?action=add&patient_id=<?= $patient['id'] ?>" 
                       class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                        <i class="fas fa-calendar-plus ml-2"></i>
                        حجز موعد
                    </a>
                    
                    <?php if (!$current_waiting): ?>
                        <a href="waiting_list.php?action=add&patient_id=<?= $patient['id'] ?>" 
                           class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                            <i class="fas fa-clock ml-2"></i>
                            إضافة للانتظار
                        </a>
                    <?php else: ?>
                        <div class="bg-yellow-100 border border-yellow-300 text-yellow-800 px-6 py-3 rounded-lg flex items-center">
                            <i class="fas fa-clock ml-2"></i>
                            في قائمة الانتظار
                        </div>
                    <?php endif; ?>
                    
                    <a href="?action=edit&id=<?= $patient['id'] ?>" 
                       class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                        <i class="fas fa-edit ml-2"></i>
                        تعديل البيانات
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-6 mb-8 fade-in">
 

            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المواعيد المكتملة</p>
                        <p class="text-3xl font-bold text-green-600"><?= $appointment_stats['completed_appointments'] ?></p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-check-circle text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المواعيد الملغية</p>
                        <p class="text-3xl font-bold text-red-600"><?= $appointment_stats['cancelled_appointments'] ?></p>
                    </div>
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-times-circle text-red-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">آخر زيارة</p>
                        <p class="text-lg font-bold text-purple-600">
                            <?= $appointment_stats['last_visit'] ? date('d/m/Y', strtotime($appointment_stats['last_visit'])) : 'لم يزر بعد' ?>
                        </p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i class="fas fa-history text-purple-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Patient Information -->
            <div class="lg:col-span-2">
                <!-- Edit Form -->
                <?php if ($action === 'edit'): ?>
                    <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-edit text-blue-600 ml-2"></i>
                                تعديل بيانات المريض
                            </h3>
                            <a href="?id=<?= $patient['id'] ?>" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-2xl"></i>
                            </a>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="action" value="update">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">الاسم الكامل *</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($patient['name']) ?>" required 
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">رقم الهاتف *</label>
                                    <input type="tel" name="phone" value="<?= htmlspecialchars($patient['phone']) ?>" required 
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">العمر *</label>
                                    <input type="number" name="age" value="<?= $patient['age'] ?>" required min="1" max="150"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">الجنس *</label>
                                    <select name="gender" required 
                                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <option value="male" <?= $patient['gender'] === 'male' ? 'selected' : '' ?>>ذكر</option>
                                        <option value="female" <?= $patient['gender'] === 'female' ? 'selected' : '' ?>>أنثى</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">البريد الإلكتروني</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" 
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">هاتف الطوارئ</label>
                                    <input type="tel" name="emergency_contact" value="<?= htmlspecialchars($patient['emergency_contact'] ?? '') ?>" 
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">فصيلة الدم</label>
                                    <select name="blood_type" 
                                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                        <option value="">غير محدد</option>
                                        <option value="A+" <?= $patient['blood_type'] === 'A+' ? 'selected' : '' ?>>A+</option>
                                        <option value="A-" <?= $patient['blood_type'] === 'A-' ? 'selected' : '' ?>>A-</option>
                                        <option value="B+" <?= $patient['blood_type'] === 'B+' ? 'selected' : '' ?>>B+</option>
                                        <option value="B-" <?= $patient['blood_type'] === 'B-' ? 'selected' : '' ?>>B-</option>
                                        <option value="AB+" <?= $patient['blood_type'] === 'AB+' ? 'selected' : '' ?>>AB+</option>
                                        <option value="AB-" <?= $patient['blood_type'] === 'AB-' ? 'selected' : '' ?>>AB-</option>
                                        <option value="O+" <?= $patient['blood_type'] === 'O+' ? 'selected' : '' ?>>O+</option>
                                        <option value="O-" <?= $patient['blood_type'] === 'O-' ? 'selected' : '' ?>>O-</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">العنوان</label>
                                <textarea name="address" rows="2"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">التاريخ المرضي</label>
                                <textarea name="medical_history" rows="3"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                          placeholder="الأمراض المزمنة، الأدوية، العمليات السابقة..."><?= htmlspecialchars($patient['medical_history'] ?? '') ?></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الحساسية</label>
                                <textarea name="allergies" rows="2"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                          placeholder="أي حساسية من أدوية أو مواد معينة..."><?= htmlspecialchars($patient['allergies'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="flex space-x-4 space-x-reverse pt-4">
                                <button type="submit" 
                                        class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ التغييرات
                                </button>
                                <a href="?id=<?= $patient['id'] ?>" 
                                   class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                                    إلغاء
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Patient Details -->
                <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-user-circle text-blue-600 ml-2"></i>
                        المعلومات الشخصية
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-user text-gray-500 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">الاسم الكامل</p>
                                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($patient['name']) ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-phone text-gray-500 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">رقم الهاتف</p>
                                    <p class="font-semibold text-gray-900">
                                        <a href="tel:<?= $patient['phone'] ?>" class="text-green-600 hover:text-green-800">
                                            <?= $patient['phone'] ?>
                                        </a>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-birthday-cake text-gray-500 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">العمر</p>
                                    <p class="font-semibold text-gray-900"><?= $patient['age'] ?> سنة</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-<?= $patient['gender'] === 'male' ? 'mars' : 'venus' ?> text-gray-500 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">الجنس</p>
                                    <p class="font-semibold text-gray-900"><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
 
                            
                            <?php if ($patient['emergency_contact']): ?>
                                <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                    <i class="fas fa-phone-alt text-gray-500 ml-3"></i>
                                    <div>
                                        <p class="text-sm text-gray-600">هاتف الطوارئ</p>
                                        <p class="font-semibold text-gray-900">
                                            <a href="tel:<?= $patient['emergency_contact'] ?>" class="text-red-600 hover:text-red-800">
                                                <?= $patient['emergency_contact'] ?>
                                            </a>
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
  
                            
                            <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                <i class="fas fa-calendar-plus text-gray-500 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">تاريخ التسجيل</p>
                                    <p class="font-semibold text-gray-900"><?= date('d/m/Y', strtotime($patient['registration_date'])) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($patient['address']): ?>
                        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-start">
                                <i class="fas fa-map-marker-alt text-gray-500 mt-1 ml-3"></i>
                                <div>
                                    <p class="text-sm text-gray-600">العنوان</p>
                                    <p class="font-semibold text-gray-900"><?= htmlspecialchars($patient['address']) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Medical Information -->
                <?php if ($patient['medical_history'] || $patient['allergies']): ?>
                    <div class="medical-alert rounded-lg shadow-lg p-8 mb-8 fade-in border-r-4 border-red-400">
                        <h3 class="text-2xl font-bold text-red-800 mb-6">
                            <i class="fas fa-exclamation-triangle text-red-600 ml-2"></i>
                            المعلومات الطبية المهمة
                        </h3>
                        
                        <?php if ($patient['medical_history']): ?>
                            <div class="mb-6 p-4 bg-white border border-red-200 rounded-lg">
                                <h4 class="font-semibold text-red-800 mb-3 flex items-center">
                                    <i class="fas fa-file-medical text-red-600 ml-2"></i>
                                    التاريخ المرضي
                                </h4>
                                <p class="text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($patient['medical_history'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
 
                        
                        <div class="mt-4 p-3 bg-yellow-100 border border-yellow-300 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-info-circle ml-1"></i>
                                <strong>تنبيه:</strong> يرجى مراجعة هذه المعلومات قبل أي إجراء طبي.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Appointments History -->
                <div class="bg-white rounded-lg shadow-lg p-8 fade-in">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-bold text-gray-800">
                            <i class="fas fa-history text-purple-600 ml-2"></i>
                            الخدمات المنجزة
                        </h3>
 
                    </div>
                    
                    <?php if (empty($appointments)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-calendar-times text-6xl text-gray-300 mb-4"></i>
                            <h4 class="text-lg font-medium text-gray-900 mb-2">لا توجد مواعيد سابقة</h4>
                            <p class="text-gray-600 mb-6">لم يحجز هذا المريض أي مواعيد بعد.</p>
                            <a href="appointments.php?action=add&patient_id=<?= $patient['id'] ?>" 
                               class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition">
                                <i class="fas fa-calendar-plus ml-1"></i>
                                حجز أول موعد
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 max-h-96 overflow-y-auto">
                            <?php foreach ($appointments as $appointment): ?>
                                <?php
                                $status_classes = [
                                    'scheduled' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                                    'confirmed' => 'bg-blue-100 text-blue-800 border-blue-200',
                                    'completed' => 'bg-green-100 text-green-800 border-green-200',
                                    'cancelled' => 'bg-red-100 text-red-800 border-red-200'
                                ];
                                $status_texts = [
                                    'scheduled' => 'مجدول',
                                    'confirmed' => 'مؤكد',
                                    'completed' => 'مكتمل',
                                    'cancelled' => 'ملغي'
                                ];
                                $status_icons = [
                                    'scheduled' => 'clock',
                                    'confirmed' => 'check',
                                    'completed' => 'check-circle',
                                    'cancelled' => 'times-circle'
                                ];
                                ?>
                                <div class="border rounded-lg p-4 hover:shadow-md transition status-<?= $appointment['status'] ?>">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1">
                                            <div class="flex items-center mb-2">
                                                <i class="fas fa-tooth text-blue-500 ml-2"></i>
                                                <h5 class="font-semibold text-gray-800"><?= htmlspecialchars($appointment['treatment_type']) ?></h5>
                                            </div>
                                            <div class="flex items-center text-sm text-gray-600 space-x-4 space-x-reverse">
                                                <span class="flex items-center">
                                                    <i class="fas fa-calendar text-gray-500 ml-1"></i>
                                                    <?= $appointment['formatted_date'] ?>
                                                </span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-clock text-gray-500 ml-1"></i>
                                                    <?= date('h:i A', strtotime($appointment['appointment_time'])) ?>
                                                </span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-hourglass-half text-gray-500 ml-1"></i>
                                                    <?= $appointment['estimated_duration'] ?> دقيقة
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="<?= $status_classes[$appointment['status']] ?? 'bg-gray-100 text-gray-800 border-gray-200' ?> border px-3 py-1 rounded-full text-xs font-medium flex items-center">
                                                <i class="fas fa-<?= $status_icons[$appointment['status']] ?? 'question' ?> ml-1"></i>
                                                <?= $status_texts[$appointment['status']] ?? $appointment['status'] ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($appointment['notes']): ?>
                                        <div class="bg-blue-50 border-r-4 border-blue-300 p-3 mt-3 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-sticky-note ml-1"></i>
                                                <strong>ملاحظات:</strong> <?= htmlspecialchars($appointment['notes']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($appointments) >= 20): ?>
                            <div class="mt-4 text-center">
                                <p class="text-sm text-gray-600">عرض آخر 20 موعد فقط</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-8">
                <!-- Current Status -->
                <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                    <h4 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-info-circle text-blue-600 ml-2"></i>
                        الحالة الحالية
                    </h4>
                    
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <span class="text-sm text-gray-600">حالة المريض</span>
                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-medium">
                                <?= $patient['status'] === 'active' ? 'نشط' : 'غير نشط' ?>
                            </span>
                        </div>
                        
                        <?php if ($current_waiting): ?>
                            <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <div class="flex items-center mb-2">
                                    <i class="fas fa-clock text-yellow-600 ml-2"></i>
                                    <span class="font-semibold text-yellow-800">في قائمة الانتظار</span>
                                </div>
                                <p class="text-sm text-yellow-700">
                                    الأولوية: 
                                    <?php
                                    $priorities = ['emergency' => 'طارئ', 'urgent' => 'مستعجل', 'normal' => 'عادي'];
                                    echo $priorities[$current_waiting['priority']] ?? $current_waiting['priority'];
                                    ?>
                                </p>
                                <p class="text-xs text-yellow-600 mt-1">
                                    وقت الوصول: <?= date('H:i', strtotime($current_waiting['arrival_time'])) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($appointment_stats['last_visit']): ?>
                            <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-center mb-1">
                                    <i class="fas fa-calendar-check text-blue-600 ml-2"></i>
                                    <span class="font-semibold text-blue-800">آخر زيارة</span>
                                </div>
                                <p class="text-sm text-blue-700">
                                    <?= date('d/m/Y', strtotime($appointment_stats['last_visit'])) ?>
                                    <?php
                                    $days_since = floor((time() - strtotime($appointment_stats['last_visit'])) / (60 * 60 * 24));
                                    if ($days_since == 0) {
                                        echo ' (اليوم)';
                                    } elseif ($days_since == 1) {
                                        echo ' (أمس)';
                                    } elseif ($days_since < 30) {
                                        echo " (منذ $days_since يوم)";
                                    } elseif ($days_since < 365) {
                                        $months = floor($days_since / 30);
                                        echo " (منذ $months شهر)";
                                    } else {
                                        $years = floor($days_since / 365);
                                        echo " (منذ $years سنة)";
                                    }
                                    ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                

                <!-- Patient Timeline -->
                <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                    <h4 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-timeline text-indigo-600 ml-2"></i>
                        الخط الزمني
                    </h4>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="bg-green-100 p-2 rounded-full ml-3 mt-1">
                                <i class="fas fa-user-plus text-green-600 text-sm"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800">تسجيل المريض</p>
                                <p class="text-sm text-gray-600">
                                    <?= date('d/m/Y', strtotime($patient['registration_date'])) ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if ($appointment_stats['first_visit'] && $appointment_stats['first_visit'] !== $appointment_stats['last_visit']): ?>
                            <div class="flex items-start">
                                <div class="bg-blue-100 p-2 rounded-full ml-3 mt-1">
                                    <i class="fas fa-calendar-check text-blue-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800">أول زيارة</p>
                                    <p class="text-sm text-gray-600">
                                        <?= date('d/m/Y', strtotime($appointment_stats['first_visit'])) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($appointment_stats['last_visit']): ?>
                            <div class="flex items-start">
                                <div class="bg-purple-100 p-2 rounded-full ml-3 mt-1">
                                    <i class="fas fa-calendar text-purple-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800">آخر زيارة</p>
                                    <p class="text-sm text-gray-600">
                                        <?= date('d/m/Y', strtotime($appointment_stats['last_visit'])) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($patient['updated_at'] && $patient['updated_at'] !== $patient['created_at']): ?>
                            <div class="flex items-start">
                                <div class="bg-yellow-100 p-2 rounded-full ml-3 mt-1">
                                    <i class="fas fa-edit text-yellow-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800">آخر تحديث</p>
                                    <p class="text-sm text-gray-600">
                                        <?= date('d/m/Y H:i', strtotime($patient['updated_at'])) ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const name = this.querySelector('input[name="name"]')?.value.trim();
            const phone = this.querySelector('input[name="phone"]')?.value.trim();
            const age = this.querySelector('input[name="age"]')?.value;
            const gender = this.querySelector('select[name="gender"]')?.value;
            
            if (!name || !phone || !age || !gender) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                return;
            }
            
            if (!/^05[0-9]{8}$/.test(phone)) {
                e.preventDefault();
                alert('رقم الهاتف غير صحيح. يجب أن يبدأ بـ 05 ويحتوي على 10 أرقام');
                return;
            }
            
            if (age < 1 || age > 150) {
                e.preventDefault();
                alert('العمر يجب أن يكون بين 1 و 150 سنة');
                return;
            }
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
        
        // Auto-focus on first input if edit form is open
        if (document.querySelector('input[name="name"]')) {
            document.querySelector('input[name="name"]').focus();
        }
        
        // Smooth scrolling for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
        
        // Print functionality
        window.addEventListener('beforeprint', function() {
            // Hide navigation and action buttons when printing
            document.querySelectorAll('nav, .no-print').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Show navigation and action buttons after printing
            document.querySelectorAll('nav, .no-print').forEach(el => {
                el.style.display = '';
            });
        });
    </script>
</body>
</html>