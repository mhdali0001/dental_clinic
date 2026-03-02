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

// معالجة إضافة/تعديل نوع علاج
if ($_POST && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add_treatment_type') {
            $stmt = $pdo->prepare("
                INSERT INTO treatment_types (code, name_ar, name_en, description_ar, description_en, icon_class, icon_color, display_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $_POST['code'], $_POST['name_ar'], $_POST['name_en'],
                $_POST['description_ar'], $_POST['description_en'],
                $_POST['icon_class'], $_POST['icon_color'], $_POST['display_order']
            ]);
            if ($result) $success_message = "تم إضافة نوع العلاج بنجاح";
            
        } elseif ($_POST['action'] === 'update_treatment_type') {
            $stmt = $pdo->prepare("
                UPDATE treatment_types SET 
                name_ar = ?, name_en = ?, description_ar = ?, description_en = ?, 
                icon_class = ?, icon_color = ?, display_order = ?, is_active = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $_POST['name_ar'], $_POST['name_en'], $_POST['description_ar'], $_POST['description_en'],
                $_POST['icon_class'], $_POST['icon_color'], $_POST['display_order'], 
                isset($_POST['is_active']) ? 1 : 0, $_POST['id']
            ]);
            if ($result) $success_message = "تم تحديث نوع العلاج بنجاح";
            
        } elseif ($_POST['action'] === 'add_treatment_option') {
            $stmt = $pdo->prepare("
                INSERT INTO treatment_options (treatment_type_code, option_code, name_ar, name_en, description_ar, description_en, display_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $_POST['treatment_type_code'], $_POST['option_code'], $_POST['name_ar'], $_POST['name_en'],
                $_POST['description_ar'], $_POST['description_en'], $_POST['display_order']
            ]);
            if ($result) $success_message = "تم إضافة خيار العلاج بنجاح";
            
        } elseif ($_POST['action'] === 'add_treatment_stage') {
            $stmt = $pdo->prepare("
                INSERT INTO treatment_stages (treatment_type_code, stage_order, title_ar, title_en, description_ar, description_en, duration_ar, duration_en) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $_POST['treatment_type_code'], $_POST['stage_order'], $_POST['title_ar'], $_POST['title_en'],
                $_POST['description_ar'], $_POST['description_en'], $_POST['duration_ar'], $_POST['duration_en']
            ]);
            if ($result) $success_message = "تم إضافة مرحلة العلاج بنجاح";
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    }
}

// جلب أنواع العلاج
try {
    $treatment_types_stmt = $pdo->query("SELECT * FROM treatment_types ORDER BY display_order, name_ar");
    $treatment_types = $treatment_types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_types = [];
}

// جلب خيارات العلاج
try {
    $treatment_options_stmt = $pdo->query("
        SELECT to.*, tt.name_ar as treatment_name 
        FROM treatment_options to 
        JOIN treatment_types tt ON to.treatment_type_code = tt.code 
        ORDER BY tt.display_order, to.display_order
    ");
    $treatment_options = $treatment_options_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_options = [];
}

// جلب مراحل العلاج
try {
    $treatment_stages_stmt = $pdo->query("
        SELECT ts.*, tt.name_ar as treatment_name 
        FROM treatment_stages ts 
        JOIN treatment_types tt ON ts.treatment_type_code = tt.code 
        ORDER BY tt.display_order, ts.stage_order
    ");
    $treatment_stages = $treatment_stages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_stages = [];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة أنواع العلاج - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-lg border-b-4 border-blue-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-cogs text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-4">
                        <h1 class="text-2xl font-bold text-gray-900">إدارة أنواع العلاج</h1>
                        <p class="text-gray-600">إدارة أنواع العلاج وخياراتها ومراحلها</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4 space-x-reverse">
                    <a href="../admin/dashboard.php" class="text-gray-600 hover:text-gray-900 px-3 py-2">
                        <i class="fas fa-arrow-right ml-1"></i>
                        العودة للوحة التحكم
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-check-circle ml-1"></i>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="bg-white rounded-lg shadow-lg mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex">
                    <button class="tab-button active bg-blue-50 border-b-2 border-blue-500 py-4 px-6 text-blue-600 font-medium" 
                            onclick="switchTab('types')">
                        أنواع العلاج
                    </button>
                    <button class="tab-button py-4 px-6 text-gray-500 font-medium hover:text-gray-700" 
                            onclick="switchTab('options')">
                        خيارات العلاج
                    </button>
                    <button class="tab-button py-4 px-6 text-gray-500 font-medium hover:text-gray-700" 
                            onclick="switchTab('stages')">
                        مراحل العلاج
                    </button>
                </nav>
            </div>
        </div>

        <!-- Treatment Types Tab -->
        <div id="types-tab" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Add Treatment Type Form -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus text-green-600 ml-2"></i>
                        إضافة نوع علاج جديد
                    </h3>
                    
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_treatment_type">
                        
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">كود النوع *</label>
                            <input type="text" name="code" required 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الاسم بالعربية *</label>
                                <input type="text" name="name_ar" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الاسم بالإنجليزية *</label>
                                <input type="text" name="name_en" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالعربية</label>
                                <textarea name="description_ar" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالإنجليزية</label>
                                <textarea name="description_en" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">كلاس الأيقونة</label>
                                <input type="text" name="icon_class" value="fas fa-tooth" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">لون الأيقونة</label>
                                <input type="text" name="icon_color" value="text-blue-500" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">ترتيب العرض</label>
                                <input type="number" name="display_order" value="0" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-lg">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة نوع العلاج
                        </button>
                    </form>
                </div>

                <!-- Treatment Types List -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        أنواع العلاج الحالية
                    </h3>
                    
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($treatment_types as $type): ?>
                            <div class="border border-gray-200 rounded-lg p-4 <?= $type['is_active'] ? 'bg-white' : 'bg-gray-50' ?>">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3 space-x-reverse">
                                        <i class="<?= htmlspecialchars($type['icon_class']) ?> <?= htmlspecialchars($type['icon_color']) ?>"></i>
                                        <div>
                                            <h4 class="font-semibold text-gray-900"><?= htmlspecialchars($type['name_ar']) ?></h4>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($type['description_ar']) ?></p>
                                            <span class="text-xs text-gray-500">كود: <?= htmlspecialchars($type['code']) ?></span>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        <span class="px-2 py-1 text-xs rounded-full <?= $type['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                            <?= $type['is_active'] ? 'نشط' : 'غير نشط' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Treatment Options Tab -->
        <div id="options-tab" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Add Treatment Option Form -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus text-green-600 ml-2"></i>
                        إضافة خيار علاج جديد
                    </h3>
                    
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_treatment_option">
                        
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                            <select name="treatment_type_code" required 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">اختر نوع العلاج...</option>
                                <?php foreach ($treatment_types as $type): ?>
                                    <option value="<?= htmlspecialchars($type['code']) ?>">
                                        <?= htmlspecialchars($type['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">كود الخيار *</label>
                            <input type="text" name="option_code" required 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الاسم بالعربية *</label>
                                <input type="text" name="name_ar" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الاسم بالإنجليزية *</label>
                                <input type="text" name="name_en" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالعربية</label>
                                <textarea name="description_ar" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالإنجليزية</label>
                                <textarea name="description_en" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">ترتيب العرض</label>
                            <input type="number" name="display_order" value="0" 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-lg">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة خيار العلاج
                        </button>
                    </form>
                </div>

                <!-- Treatment Options List -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        خيارات العلاج الحالية
                    </h3>
                    
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($treatment_options as $option): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h4 class="font-semibold text-gray-900"><?= htmlspecialchars($option['name_ar']) ?></h4>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($option['description_ar']) ?></p>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded"><?= htmlspecialchars($option['treatment_name']) ?></span>
                                            <span class="mr-2">كود: <?= htmlspecialchars($option['option_code']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Treatment Stages Tab -->
        <div id="stages-tab" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Add Treatment Stage Form -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus text-green-600 ml-2"></i>
                        إضافة مرحلة علاج جديدة
                    </h3>
                    
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_treatment_stage">
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                                <select name="treatment_type_code" required 
                                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">اختر نوع العلاج...</option>
                                    <?php foreach ($treatment_types as $type): ?>
                                        <option value="<?= htmlspecialchars($type['code']) ?>">
                                            <?= htmlspecialchars($type['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">ترتيب المرحلة *</label>
                                <input type="number" name="stage_order" required min="1" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">عنوان المرحلة بالعربية *</label>
                                <input type="text" name="title_ar" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">عنوان المرحلة بالإنجليزية *</label>
                                <input type="text" name="title_en" required 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالعربية</label>
                                <textarea name="description_ar" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">الوصف بالإنجليزية</label>
                                <textarea name="description_en" rows="3" 
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">المدة بالعربية</label>
                                <input type="text" name="duration_ar" placeholder="مثل: 30 دقيقة" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-gray-700 font-semibold mb-2">المدة بالإنجليزية</label>
                                <input type="text" name="duration_en" placeholder="e.g: 30 minutes" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-lg">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة مرحلة العلاج
                        </button>
                    </form>
                </div>

                <!-- Treatment Stages List -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        مراحل العلاج الحالية
                    </h3>
                    
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($treatment_stages as $stage): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center mb-2">
                                            <span class="bg-blue-500 text-white px-2 py-1 rounded-full text-xs font-bold mr-3">
                                                <?= $stage['stage_order'] ?>
                                            </span>
                                            <h4 class="font-semibold text-gray-900"><?= htmlspecialchars($stage['title_ar']) ?></h4>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($stage['description_ar']) ?></p>
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded"><?= htmlspecialchars($stage['treatment_name']) ?></span>
                                            <?php if ($stage['duration_ar']): ?>
                                                <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded"><?= htmlspecialchars($stage['duration_ar']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active', 'bg-blue-50', 'border-b-2', 'border-blue-500', 'text-blue-600');
                button.classList.add('text-gray-500', 'hover:text-gray-700');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.remove('hidden');
            
            // Add active class to selected tab button
            event.target.classList.add('active', 'bg-blue-50', 'border-b-2', 'border-blue-500', 'text-blue-600');
            event.target.classList.remove('text-gray-500', 'hover:text-gray-700');
        }
    </script>
</body>
</html>