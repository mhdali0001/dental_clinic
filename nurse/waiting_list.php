<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('nurse');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// إضافة مريض لقائمة الانتظار
if ($_POST && $action === 'add') {
    try {
        // التحقق من عدم وجود المريض في القائمة بالفعل
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM waiting_list WHERE patient_id = ? AND status = 'waiting'");
        $stmt->execute([$_POST['patient_id']]);
        
        if ($stmt->fetchColumn() > 0) {
            $error_message = "المريض موجود بالفعل في قائمة الانتظار!";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO waiting_list (patient_id, priority, notes, arrival_time, status) 
                VALUES (?, ?, ?, NOW(), 'waiting')
            ");
            
            $result = $stmt->execute([
                $_POST['patient_id'],
                $_POST['priority'] ?? 'normal',
                $_POST['notes'] ?? ''
            ]);
            
            if ($result) {
                $success_message = "تم إضافة المريض لقائمة الانتظار بنجاح";
                $action = ''; // إخفاء النموذج
            }
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إضافة المريض: " . $e->getMessage();
    }
}

// استدعاء مريض
if ($action === 'call' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE waiting_list SET status = 'called', called_time = NOW() WHERE id = ?");
        $result = $stmt->execute([$_GET['id']]);
        if ($result) {
            $success_message = "تم استدعاء المريض بنجاح";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في استدعاء المريض: " . $e->getMessage();
    }
}

// حذف من قائمة الانتظار
if ($action === 'remove' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
        $result = $stmt->execute([$_GET['id']]);
        if ($result) {
            $success_message = "تم حذف المريض من قائمة الانتظار";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في حذف المريض: " . $e->getMessage();
    }
}

// تغيير الأولوية
if ($action === 'change_priority' && isset($_GET['id']) && isset($_GET['priority'])) {
    try {
        $stmt = $pdo->prepare("UPDATE waiting_list SET priority = ? WHERE id = ?");
        $result = $stmt->execute([$_GET['priority'], $_GET['id']]);
        if ($result) {
            $success_message = "تم تغيير الأولوية بنجاح";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في تغيير الأولوية: " . $e->getMessage();
    }
}

// مسح جميع قائمة الانتظار
if ($action === 'clear_all' && isset($_GET['confirm'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM waiting_list WHERE status = 'waiting'");
        $result = $stmt->execute();
        if ($result) {
            $success_message = "تم مسح جميع قائمة الانتظار";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في مسح القائمة: " . $e->getMessage();
    }
}

// جلب قائمة الانتظار مصنفة حسب الأولوية
try {
    $stmt = $pdo->prepare("
        SELECT wl.*, p.name as patient_name, p.phone, p.age, p.gender,
               p.medical_history, p.allergies, p.blood_type
        FROM waiting_list wl 
        JOIN patients p ON wl.patient_id = p.id 
        WHERE wl.status = 'waiting' 
        ORDER BY 
            CASE wl.priority 
                WHEN 'emergency' THEN 1 
                WHEN 'urgent' THEN 2 
                WHEN 'normal' THEN 3 
            END,
            wl.arrival_time ASC
    ");
    $stmt->execute();
    $waiting_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تصنيف المرضى حسب الأولوية
    $emergency_patients = array_filter($waiting_list, fn($p) => $p['priority'] === 'emergency');
    $urgent_patients = array_filter($waiting_list, fn($p) => $p['priority'] === 'urgent');
    $normal_patients = array_filter($waiting_list, fn($p) => $p['priority'] === 'normal');
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $waiting_list = [];
    $emergency_patients = $urgent_patients = $normal_patients = [];
}

// جلب قائمة المرضى للنموذج
try {
    $patients_stmt = $pdo->query("SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name");
    $patients_list = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients_list = [];
}

// مريض محدد مسبقاً
$preselected_patient = $_GET['patient_id'] ?? null;

// حساب الإحصائيات
$emergency_count = count($emergency_patients);
$urgent_count = count($urgent_patients);
$normal_count = count($normal_patients);
$total_waiting = count($waiting_list);

// Set page variables for header
$pageTitle = 'قائمة الانتظار';
$pageIcon = 'fas fa-clock';
$pageSubtitle = 'إجمالي المنتظرين: ' . $total_waiting;
$currentPage = 'waiting_list';

// دالة لعرض بطاقة المريض
function renderPatientCard($patient, $position, $priority) {
    $priority_classes = [
        'emergency' => 'border-red-200 emergency-blink',
        'urgent' => 'border-yellow-200 bg-yellow-50',
        'normal' => 'border-green-200 bg-green-50'
    ];
    
    $priority_badges = [
        'emergency' => 'bg-red-500 text-white',
        'urgent' => 'bg-yellow-500 text-white',
        'normal' => 'bg-green-500 text-white'
    ];
    
    $priority_texts = [
        'emergency' => 'طارئ',
        'urgent' => 'مستعجل',
        'normal' => 'عادي'
    ];
    
    $waiting_time = getWaitingTime($patient['arrival_time']);
    
    $html = '
    <div class="patient-card border rounded-lg p-4 ' . $priority_classes[$priority] . '">
        <div class="flex justify-between items-start mb-3">
            <div class="flex-1">
                <div class="flex items-center mb-2">
                    <span class="bg-white text-gray-800 text-xs font-bold px-2 py-1 rounded-full ml-2">#' . $position . '</span>
                    <h6 class="font-semibold text-gray-800">' . htmlspecialchars($patient['patient_name']) . '</h6>
                </div>
                <div class="text-sm text-gray-600 space-y-1">
                    <p class="flex items-center">
                        <i class="fas fa-phone text-gray-500 ml-1"></i>
                        <a href="tel:' . $patient['phone'] . '" class="text-green-600 hover:text-green-800">' . $patient['phone'] . '</a>
                    </p>
                    <p class="flex items-center">
                        <i class="fas fa-clock text-gray-500 ml-1"></i>
                        انتظار: ' . $waiting_time . '
                    </p>
                    <p class="flex items-center">
                        <i class="fas fa-user text-gray-500 ml-1"></i>
                        ' . $patient['age'] . ' سنة - ' . ($patient['gender'] === 'male' ? 'ذكر' : 'أنثى') . '
                    </p>
                </div>
            </div>
            <div class="text-left">
                <span class="' . $priority_badges[$priority] . ' text-xs px-2 py-1 rounded-full font-medium">
                    ' . $priority_texts[$priority] . '
                </span>
            </div>
        </div>';
        
    // Medical alerts
    if ($patient['medical_history'] || $patient['allergies']) {
        $html .= '
        <div class="bg-red-50 border-r-2 border-red-300 p-2 mb-3">
            <p class="text-xs text-red-800">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <strong>تنبيه طبي:</strong>';
        
        if ($patient['allergies']) {
            $html .= ' حساسية: ' . htmlspecialchars($patient['allergies']);
        }
        if ($patient['medical_history']) {
            $html .= ($patient['allergies'] ? ' | ' : '') . htmlspecialchars(substr($patient['medical_history'], 0, 30)) . (strlen($patient['medical_history']) > 30 ? '...' : '');
        }
        
        $html .= '
            </p>
        </div>';
    }
    
    // Notes
    if ($patient['notes']) {
        $html .= '
        <div class="bg-blue-50 border-r-2 border-blue-300 p-2 mb-3">
            <p class="text-xs text-blue-800">
                <i class="fas fa-sticky-note ml-1"></i>
                ' . htmlspecialchars($patient['notes']) . '
            </p>
        </div>';
    }
    
    // Action buttons
    $html .= '
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="?action=call&id=' . $patient['id'] . '" 
               class="flex-1 bg-green-500 hover:bg-green-600 text-white text-xs px-3 py-2 rounded transition text-center">
                <i class="fas fa-volume-up ml-1"></i>
                استدعاء
            </a>
            <a href="?action=change_priority&id=' . $patient['id'] . '&priority=' . ($priority === 'emergency' ? 'urgent' : ($priority === 'urgent' ? 'normal' : 'emergency')) . '" 
               class="bg-blue-500 hover:bg-blue-600 text-white text-xs px-2 py-2 rounded transition">
                <i class="fas fa-sort ml-1"></i>
            </a>
            <a href="patient_details.php?id=' . $patient['patient_id'] . '" 
               class="bg-purple-500 hover:bg-purple-600 text-white text-xs px-2 py-2 rounded transition">
                <i class="fas fa-eye ml-1"></i>
            </a>
            <a href="?action=remove&id=' . $patient['id'] . '" 
               onclick="return confirm(\'هل أنت متأكد من حذف هذا المريض من قائمة الانتظار؟\')"
               class="bg-red-500 hover:bg-red-600 text-white text-xs px-2 py-2 rounded transition">
                <i class="fas fa-trash ml-1"></i>
            </a>
        </div>
    </div>';
    
    return $html;
}

// دالة حساب وقت الانتظار
function getWaitingTime($arrival_time) {
    $arrival = new DateTime($arrival_time);
    $now = new DateTime();
    $diff = $now->diff($arrival);
    
    if ($diff->h > 0) {
        return $diff->h . ':' . str_pad($diff->i, 2, '0', STR_PAD_LEFT) . ' ساعة';
    } else {
        return $diff->i . ' دقيقة';
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قائمة الانتظار - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .emergency-blink { animation: blink 1s infinite; }
        @keyframes blink { 0%, 50% { background-color: #ef4444; } 51%, 100% { background-color: #dc2626; } }
        .pulse-ring { animation: pulse-ring 2s cubic-bezier(0.455, 0.03, 0.515, 0.955) infinite; }
        @keyframes pulse-ring { 0% { transform: scale(0.33); } 40%, 50% { opacity: 1; } 100% { opacity: 0; transform: scale(1.2); } }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/nurse_header.php'; ?>

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

        <!-- Quick Actions and Statistics -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <!-- Statistics -->
                <div class="grid grid-cols-4 gap-4 flex-1">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                        <div class="flex items-center justify-center mb-2">
                            <div class="bg-red-100 p-2 rounded-full">
                                <i class="fas fa-exclamation-triangle text-red-600"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-red-600 <?= $emergency_count > 0 ? 'pulse-ring' : '' ?>"><?= $emergency_count ?></div>
                        <div class="text-sm text-red-600">حالات طارئة</div>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
                        <div class="flex items-center justify-center mb-2">
                            <div class="bg-yellow-100 p-2 rounded-full">
                                <i class="fas fa-exclamation text-yellow-600"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-yellow-600"><?= $urgent_count ?></div>
                        <div class="text-sm text-yellow-600">حالات مستعجلة</div>
                    </div>
                    
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                        <div class="flex items-center justify-center mb-2">
                            <div class="bg-green-100 p-2 rounded-full">
                                <i class="fas fa-check-circle text-green-600"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-green-600"><?= $normal_count ?></div>
                        <div class="text-sm text-green-600">حالات عادية</div>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                        <div class="flex items-center justify-center mb-2">
                            <div class="bg-blue-100 p-2 rounded-full">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-blue-600"><?= $total_waiting ?></div>
                        <div class="text-sm text-blue-600">إجمالي المنتظرين</div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-4">
                    <?php if ($total_waiting > 0): ?>
                        <a href="?action=call&id=<?= $waiting_list[0]['id'] ?? '' ?>" 
                           class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                            <i class="fas fa-volume-up ml-2"></i>
                            استدعاء التالي
                        </a>
                    <?php endif; ?>
                    
                    <a href="?action=add<?= $preselected_patient ? '&patient_id=' . $preselected_patient : '' ?>" 
                       class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة للانتظار
                    </a>
                    
                    <?php if ($total_waiting > 0): ?>
                        <a href="?action=clear_all&confirm=1" 
                           onclick="return confirm('هل أنت متأكد من مسح جميع قائمة الانتظار؟\nهذا الإجراء لا يمكن التراجع عنه.')"
                           class="bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition flex items-center justify-center">
                            <i class="fas fa-trash ml-2"></i>
                            مسح الكل
                        </a>
                    <?php endif; ?>
                    
                    <button onclick="window.print()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add to Waiting List Form -->
        <?php if ($action === 'add'): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-plus text-green-600 ml-2"></i>
                        إضافة مريض لقائمة الانتظار
                    </h3>
                    <a href="?" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add">
                    
                    <!-- Patient Selection -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">المريض *</label>
                        <select name="patient_id" required 
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">اختر مريض...</option>
                            <?php foreach ($patients_list as $patient): ?>
                                <option value="<?= $patient['id'] ?>" <?= $preselected_patient == $patient['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($patient['name']) ?> - <?= $patient['phone'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($patients_list)): ?>
                            <p class="text-red-600 text-sm mt-1">
                                لا توجد مرضى مسجلين. 
                                <a href="patients.php?action=add" class="underline">إضافة مريض جديد</a>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Priority Selection -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">الأولوية *</label>
                        <div class="space-y-3">
                            <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 transition">
                                <input type="radio" name="priority" value="normal" checked class="text-green-500 ml-3">
                                <div class="flex items-center">
                                    <span class="text-2xl ml-3">🟢</span>
                                    <div>
                                        <div class="font-medium text-gray-900">عادي</div>
                                        <div class="text-sm text-gray-600">حالة روتينية - الأولوية الطبيعية</div>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-yellow-50 transition">
                                <input type="radio" name="priority" value="urgent" class="text-yellow-500 ml-3">
                                <div class="flex items-center">
                                    <span class="text-2xl ml-3">🟡</span>
                                    <div>
                                        <div class="font-medium text-gray-900">مستعجل</div>
                                        <div class="text-sm text-gray-600">يحتاج اهتمام سريع - أولوية متوسطة</div>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-red-50 transition">
                                <input type="radio" name="priority" value="emergency" class="text-red-500 ml-3">
                                <div class="flex items-center">
                                    <span class="text-2xl ml-3">🔴</span>
                                    <div>
                                        <div class="font-medium text-gray-900">طارئ</div>
                                        <div class="text-sm text-gray-600">حالة طوارئ فورية - أولوية قصوى</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ملاحظات (اختياري)</label>
                        <textarea name="notes" 
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent" 
                                  rows="3" 
                                  placeholder="أي ملاحظات حول حالة المريض أو سبب الزيارة..."></textarea>
                    </div>
                    
                    <!-- Information Box -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-600 mt-0.5 ml-2"></i>
                            <div class="text-sm text-blue-800">
                                <p class="font-semibold mb-1">معلومة مهمة:</p>
                                <p>سيتم ترتيب المرضى حسب الأولوية ووقت الوصول. الحالات الطارئة لها الأولوية الأولى، تليها الحالات المستعجلة، ثم الحالات العادية.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex space-x-4 space-x-reverse pt-4">
                        <button type="submit" 
                                class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة للقائمة
                        </button>
                        <a href="?" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Waiting Lists by Priority -->
        <?php if (empty($waiting_list)): ?>
            <div class="bg-white rounded-lg shadow-lg p-16 text-center fade-in">
                <i class="fas fa-clock text-6xl text-gray-300 mb-6"></i>
                <h3 class="text-2xl font-bold text-gray-900 mb-4">لا توجد مرضى في قائمة الانتظار</h3>
                <p class="text-gray-600 mb-8">قائمة الانتظار فارغة حالياً. يمكنك إضافة مرضى جدد للانتظار.</p>
                <a href="?action=add" class="bg-green-500 hover:bg-green-600 text-white px-8 py-3 rounded-lg transition">
                    <i class="fas fa-plus ml-2"></i>
                    إضافة أول مريض للانتظار
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 fade-in">
                <!-- Emergency Patients -->
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-red-500 text-white px-6 py-4">
                        <h4 class="text-lg font-bold flex items-center">
                            <i class="fas fa-exclamation-triangle ml-2"></i>
                            🔴 حالات طارئة
                            <?php if ($emergency_count > 0): ?>
                                <span class="bg-red-700 text-white text-sm px-2 py-1 rounded-full mr-2"><?= $emergency_count ?></span>
                            <?php endif; ?>
                        </h4>
                        <p class="text-red-100 text-sm">الأولوية القصوى - معالجة فورية</p>
                    </div>
                    <div class="p-6 max-h-96 overflow-y-auto">
                        <?php if (empty($emergency_patients)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-4 opacity-50"></i>
                                <p class="text-sm">لا توجد حالات طارئة</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($emergency_patients as $index => $patient): ?>
                                    <?= renderPatientCard($patient, $index + 1, 'emergency') ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Urgent Patients -->
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-yellow-500 text-white px-6 py-4">
                        <h4 class="text-lg font-bold flex items-center">
                            <i class="fas fa-exclamation ml-2"></i>
                            🟡 حالات مستعجلة
                            <?php if ($urgent_count > 0): ?>
                                <span class="bg-yellow-700 text-white text-sm px-2 py-1 rounded-full mr-2"><?= $urgent_count ?></span>
                            <?php endif; ?>
                        </h4>
                        <p class="text-yellow-100 text-sm">تحتاج اهتمام سريع</p>
                    </div>
                    <div class="p-6 max-h-96 overflow-y-auto">
                        <?php if (empty($urgent_patients)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-4 opacity-50"></i>
                                <p class="text-sm">لا توجد حالات مستعجلة</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($urgent_patients as $index => $patient): ?>
                                    <?= renderPatientCard($patient, $index + 1, 'urgent') ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Normal Patients -->
                <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                    <div class="bg-green-500 text-white px-6 py-4">
                        <h4 class="text-lg font-bold flex items-center">
                            <i class="fas fa-check-circle ml-2"></i>
                            🟢 حالات عادية
                            <?php if ($normal_count > 0): ?>
                                <span class="bg-green-700 text-white text-sm px-2 py-1 rounded-full mr-2"><?= $normal_count ?></span>
                            <?php endif; ?>
                        </h4>
                        <p class="text-green-100 text-sm">مواعيد روتينية</p>
                    </div>
                    <div class="p-6 max-h-96 overflow-y-auto">
                        <?php if (empty($normal_patients)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-4 opacity-50"></i>
                                <p class="text-sm">لا توجد حالات عادية</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($normal_patients as $index => $patient): ?>
                                    <?= renderPatientCard($patient, $index + 1, 'normal') ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // تحديث تلقائي كل دقيقتين
        setInterval(function() {
            if (!document.querySelector('form')) { // لا تحديث إذا كان النموذج مفتوح
                location.reload();
            }
        }, 120000);
        
        // صوت تنبيه للحالات الطارئة
        const emergencyCount = <?= $emergency_count ?>;
        if (emergencyCount > 0 && !sessionStorage.getItem('emergencyAlerted')) {
            // تشغيل صوت تنبيه
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhCC6Y2v');
            audio.play().catch(() => {});
            
            sessionStorage.setItem('emergencyAlerted', 'true');
            setTimeout(() => sessionStorage.removeItem('emergencyAlerted'), 300000); // إعادة التنبيه بعد 5 دقائق
        }
        
        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const patientId = this.querySelector('select[name="patient_id"]').value;
            const priority = this.querySelector('input[name="priority"]:checked')?.value;
            
            if (!patientId) {
                e.preventDefault();
                alert('يرجى اختيار مريض');
                return;
            }
            
            if (!priority) {
                e.preventDefault();
                alert('يرجى اختيار الأولوية');
                return;
            }
        });
        
        // Auto-focus on patient select if form is visible
        if (document.querySelector('select[name="patient_id"]')) {
            document.querySelector('select[name="patient_id"]').focus();
        }
    </script>
</body>
</html>