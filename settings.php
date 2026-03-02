<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$success_message = '';
$error_message = '';

// تحديد التبويب النشط
$active_tab = $_GET['tab'] ?? 'treatment_types';

// معالجة النماذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_treatment_option':
                $stmt = $pdo->prepare("
                    INSERT INTO treatment_options (treatment_type_code, option_code, name_ar, description_ar, price, display_order, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");

                $stmt->execute([
                    $_POST['treatment_type_code'],
                    $_POST['option_code'],
                    $_POST['name_ar'],
                    $_POST['description_ar'] ?? '',
                    $_POST['price'] ?? 0,
                    $_POST['display_order'] ?? 0
                ]);

                $success_message = "تم إضافة خيار العلاج بنجاح";
                break;

            case 'edit_treatment_option':
                $stmt = $pdo->prepare("
                    UPDATE treatment_options
                    SET treatment_type_code = ?, option_code = ?, name_ar = ?, description_ar = ?, price = ?, display_order = ?, is_active = ?
                    WHERE treatment_type_code = ? AND option_code = ?
                ");

                $stmt->execute([
                    $_POST['treatment_type_code'],
                    $_POST['option_code'],
                    $_POST['name_ar'],
                    $_POST['description_ar'] ?? '',
                    $_POST['price'] ?? 0,
                    $_POST['display_order'] ?? 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['original_treatment_type_code'],
                    $_POST['original_option_code']
                ]);

                $success_message = "تم تحديث خيار العلاج بنجاح";
                break;

            case 'delete_treatment_option':
                $stmt = $pdo->prepare("DELETE FROM treatment_options WHERE treatment_type_code = ? AND option_code = ?");
                $stmt->execute([$_POST['treatment_type_code'], $_POST['option_code']]);

                $success_message = "تم حذف خيار العلاج بنجاح";
                break;

            case 'add_treatment_stage':
                $stmt = $pdo->prepare("
                    INSERT INTO treatment_stages (treatment_options_code, stage_order, title_ar, description_ar, duration_ar, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ");

                $stmt->execute([
                    $_POST['treatment_options_code'],
                    $_POST['stage_order'],
                    $_POST['title_ar'],
                    $_POST['description_ar'] ?? '',
                    $_POST['duration_ar'] ?? ''
                ]);

                $success_message = "تم إضافة مرحلة العلاج بنجاح";
                break;

            case 'edit_treatment_stage':
                $stmt = $pdo->prepare("
                    UPDATE treatment_stages
                    SET treatment_options_code = ?, stage_order = ?, title_ar = ?, description_ar = ?, duration_ar = ?, is_active = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $_POST['treatment_options_code'],
                    $_POST['stage_order'],
                    $_POST['title_ar'],
                    $_POST['description_ar'] ?? '',
                    $_POST['duration_ar'] ?? '',
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['stage_id']
                ]);

                $success_message = "تم تحديث مرحلة العلاج بنجاح";
                break;

            case 'delete_treatment_stage':
                $stmt = $pdo->prepare("DELETE FROM treatment_stages WHERE id = ?");
                $stmt->execute([$_POST['stage_id']]);

                $success_message = "تم حذف مرحلة العلاج بنجاح";
                break;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في العملية: " . $e->getMessage();
    }
}

// جلب أنواع العلاج
try {
    $stmt = $pdo->query("SELECT * FROM treatment_types WHERE is_active = 1 ORDER BY display_order, name_ar");
    $treatment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_types = [];
    $error_message = "خطأ في جلب أنواع العلاج: " . $e->getMessage();
}

// جلب خيارات العلاج
try {
    $stmt = $pdo->query("
        SELECT to.*, tt.name_ar as treatment_type_name
        FROM treatment_options to
        LEFT JOIN treatment_types tt ON to.treatment_type_code = tt.code
        ORDER BY to.treatment_type_code, to.display_order, to.name_ar
    ");
    $treatment_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_options = [];
    $error_message = "خطأ في جلب خيارات العلاج: " . $e->getMessage();
}

// جلب مراحل العلاج
try {
    // Check if treatment_stages table exists and what structure it has
    $table_check = $pdo->query("SHOW TABLES LIKE 'treatment_stages'")->fetchAll();

    if (empty($table_check)) {
        // Table doesn't exist
        $treatment_stages = [];
        echo "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4'>";
        echo "<strong>تحذير:</strong> جدول مراحل العلاج غير موجود. يرجى تشغيل سكريبت تحديث قاعدة البيانات أولاً.";
        echo "</div>";
    } else {
        // Check table structure
        $columns = $pdo->query("SHOW COLUMNS FROM treatment_stages")->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('treatment_options_code', $columns)) {
            // New structure with treatment_options_code
            try {
                $stmt = $pdo->query("
                    SELECT ts.*, topt.name_ar as option_name, tt.name_ar as treatment_type_name,
                           topt.treatment_type_code, topt.option_code
                    FROM treatment_stages ts
                    LEFT JOIN treatment_options topt ON ts.treatment_options_code = topt.id
                    LEFT JOIN treatment_types tt ON topt.treatment_type_code = tt.code
                    ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
                ");
                $treatment_stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // If JOIN result is empty but table has data, try simple query without JOIN
                if (empty($treatment_stages) && $total_count > 0) {
                    echo "<div class='bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-2'>";
                    echo "<strong>تحذير:</strong> الاستعلام مع JOIN فارغ، جاري المحاولة بدون JOIN...";
                    echo "</div>";

                    $stmt = $pdo->query("SELECT * FROM treatment_stages ORDER BY stage_order");
                    $treatment_stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (PDOException $e) {
                echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-2'>";
                echo "<strong>خطأ في الاستعلام مع JOIN:</strong> " . $e->getMessage();
                echo "</div>";

                // Fallback to simple query
                $stmt = $pdo->query("SELECT * FROM treatment_stages ORDER BY stage_order");
                $treatment_stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Old structure with separate treatment_type_code and option_code
            $stmt = $pdo->query("
                SELECT ts.*, topt.name_ar as option_name, tt.name_ar as treatment_type_name
                FROM treatment_stages ts
                LEFT JOIN treatment_options topt ON ts.treatment_type_code = topt.treatment_type_code AND ts.option_code = topt.option_code
                LEFT JOIN treatment_types tt ON ts.treatment_type_code = tt.code
                ORDER BY ts.treatment_type_code, ts.option_code, ts.stage_order
            ");
            $treatment_stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Debug info
        echo "<div class='bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4'>";
        echo "<strong>معلومات التصحيح:</strong> تم العثور على " . count($treatment_stages) . " مرحلة علاج. ";
        echo "هيكل الجدول: " . (in_array('treatment_options_code', $columns) ? 'جديد' : 'قديم');
        echo "<br><strong>أعمدة الجدول:</strong> " . implode(', ', $columns);

        // Show total count from table
        $total_count = $pdo->query("SELECT COUNT(*) as count FROM treatment_stages")->fetch()['count'];
        echo "<br><strong>إجمالي السجلات في الجدول:</strong> " . $total_count;

        // Show raw data from table
        if ($total_count > 0) {
            echo "<br><strong>البيانات الخام من الجدول:</strong><br>";
            $raw_data = $pdo->query("SELECT * FROM treatment_stages LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre style='background: white; padding: 10px; border: 1px solid #ccc; font-size: 12px; max-height: 200px; overflow-y: auto;'>";
            print_r($raw_data);
            echo "</pre>";

            // Show the actual query result with JOIN
            echo "<br><strong>نتيجة الاستعلام مع JOIN:</strong><br>";
            echo "<pre style='background: white; padding: 10px; border: 1px solid #ccc; font-size: 12px; max-height: 200px; overflow-y: auto;'>";
            print_r($treatment_stages);
            echo "</pre>";

            // Show treatment_options data for reference
            echo "<br><strong>بيانات خيارات العلاج للمراجعة:</strong><br>";
            $options_sample = $pdo->query("SELECT id, treatment_type_code, option_code, name_ar FROM treatment_options LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre style='background: white; padding: 10px; border: 1px solid #ccc; font-size: 12px; max-height: 150px; overflow-y: auto;'>";
            print_r($options_sample);
            echo "</pre>";
        } else {
            echo "<br><strong>الجدول فارغ - لا توجد بيانات</strong>";
        }
        echo "</div>";
    }
} catch (PDOException $e) {
    $treatment_stages = [];
    $error_message = "خطأ في جلب مراحل العلاج: " . $e->getMessage();
    // Debug: show the error
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'>";
    echo "<strong>خطأ في قاعدة البيانات:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات النظام - عيادة الأسنان</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .tab-button.active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-lg border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <i class="fas fa-cogs text-blue-600 text-2xl ml-3"></i>
                    <h1 class="text-2xl font-bold text-gray-900">إعدادات النظام</h1>
                </div>
                <div class="flex items-center space-x-4 space-x-reverse">
                    <span class="text-gray-600">مرحباً، <?= htmlspecialchars($_SESSION['full_name'] ?? 'المستخدم') ?></span>
                    <a href="<?= $user_role === 'doctor' ? 'doctor/dashboard.php' : ($user_role === 'admin' ? 'admin/dashboard.php' : 'index.php') ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-right ml-1"></i>
                        العودة للوحة التحكم
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- رسائل النجاح والخطأ -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-1"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="mb-8">
            <nav class="flex space-x-4 space-x-reverse">
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_types' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>"
                        onclick="switchTab('treatment_types')">
                    <i class="fas fa-list ml-2"></i>
                    أنواع العلاج
                </button>
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_options' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>"
                        onclick="switchTab('treatment_options')">
                    <i class="fas fa-cog ml-2"></i>
                    خيارات العلاج
                </button>
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_stages' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>"
                        onclick="switchTab('treatment_stages')">
                    <i class="fas fa-tasks ml-2"></i>
                    مراحل العلاج
                </button>
            </nav>
        </div>

        <!-- Treatment Types Tab -->
        <div id="treatment_types_content" class="tab-content <?= $active_tab === 'treatment_types' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        إدارة أنواع العلاج
                    </h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($treatment_types as $type): ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center">
                                    <i class="fas fa-tooth text-blue-600 text-xl ml-2"></i>
                                    <span class="font-semibold text-gray-900"><?= htmlspecialchars($type['name_ar']) ?></span>
                                </div>
                            </div>
                            <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($type['description_ar'] ?? 'لا يوجد وصف') ?></p>
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span>الكود: <?= htmlspecialchars($type['code']) ?></span>
                                <span class="px-2 py-1 rounded-full <?= $type['is_active'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $type['is_active'] == 1 ? 'نشط' : 'غير نشط' ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Treatment Options Tab -->
        <div id="treatment_options_content" class="tab-content <?= $active_tab === 'treatment_options' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-cog text-orange-600 ml-2"></i>
                        إدارة خيارات العلاج
                    </h3>
                    <button onclick="openAddTreatmentOptionModal()" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg transition font-medium shadow-lg hover:shadow-xl">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة خيار علاج جديد
                    </button>
                </div>

                <?php if (empty($treatment_options)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-cog text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-600 mb-2">لا توجد خيارات علاج</h3>
                        <p class="text-gray-500 mb-6">ابدأ بإضافة خيارات العلاج لتنظيم العمليات الطبية</p>
                        <button onclick="openAddTreatmentOptionModal()" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-3 rounded-lg transition">
                            <i class="fas fa-plus ml-1"></i>
                            إضافة أول خيار علاج
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Group treatment options by type -->
                    <?php
                    $grouped_options = [];
                    foreach ($treatment_options as $option) {
                        $type_code = $option['treatment_type_code'];
                        if (!isset($grouped_options[$type_code])) {
                            $grouped_options[$type_code] = [
                                'type_name' => $option['treatment_type_name'] ?? $type_code,
                                'options' => []
                            ];
                        }
                        $grouped_options[$type_code]['options'][] = $option;
                    }
                    ?>

                    <div class="space-y-6">
                        <?php foreach ($grouped_options as $type_code => $group): ?>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-800 mb-4 flex items-center">
                                    <i class="fas fa-folder text-orange-600 ml-2"></i>
                                    <?= htmlspecialchars($group['type_name']) ?>
                                    <span class="text-sm text-gray-500 mr-2">(<?= count($group['options']) ?> خيار)</span>
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <?php foreach ($group['options'] as $option): ?>
                                        <div class="bg-white border border-gray-200 rounded p-3">
                                            <div class="flex items-center justify-between mb-2">
                                                <div>
                                                    <span class="font-medium text-gray-900"><?= htmlspecialchars($option['name_ar']) ?></span>
                                                    <?php if (!empty($option['price']) && $option['price'] > 0): ?>
                                                        <div class="text-sm font-semibold text-green-600 mt-1">
                                                            <i class="fas fa-money-bill-wave ml-1"></i>
                                                            <?= number_format($option['price'], 2) ?> ل.س
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex items-center space-x-2 space-x-reverse">
                                                    <button onclick="editTreatmentOption('<?= htmlspecialchars($option['treatment_type_code']) ?>', '<?= htmlspecialchars($option['option_code']) ?>')" class="text-blue-600 hover:text-blue-800 text-sm p-1">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="deleteTreatmentOption('<?= htmlspecialchars($option['treatment_type_code']) ?>', '<?= htmlspecialchars($option['option_code']) ?>')" class="text-red-600 hover:text-red-800 text-sm p-1">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <?php if (!empty($option['description_ar'])): ?>
                                                <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($option['description_ar']) ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center justify-between text-xs text-gray-500">
                                                <span>الكود: <?= htmlspecialchars($option['option_code']) ?></span>
                                                <span class="px-2 py-1 rounded-full <?= $option['is_active'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                    <?= $option['is_active'] == 1 ? 'نشط' : 'غير نشط' ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Treatment Stages Tab -->
        <div id="treatment_stages_content" class="tab-content <?= $active_tab === 'treatment_stages' ? 'active' : '' ?>">
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-xl p-6 mb-6 text-white">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-2xl font-bold mb-2">
                                <i class="fas fa-list-ol ml-2"></i>
                                إدارة مراحل العلاج
                            </h2>
                            <p class="text-purple-100">تنظيم وإدارة مراحل العلاج المرتبطة بخيارات العلاج المختلفة</p>
                        </div>
                        <button onclick="addTreatmentStage()" class="bg-white text-purple-600 hover:bg-purple-50 px-6 py-3 rounded-lg transition font-medium shadow-lg hover:shadow-xl">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة مرحلة جديدة
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="bg-purple-100 p-3 rounded-full">
                                <i class="fas fa-tasks text-purple-600 text-xl"></i>
                            </div>
                            <div class="mr-4">
                                <p class="text-sm text-gray-600">إجمالي المراحل</p>
                                <p class="text-2xl font-bold text-gray-800"><?= count($treatment_stages) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
                        <div class="flex items-center">
                            <div class="bg-green-100 p-3 rounded-full">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                            <div class="mr-4">
                                <p class="text-sm text-gray-600">المراحل النشطة</p>
                                <p class="text-2xl font-bold text-gray-800"><?= count(array_filter($treatment_stages, function($s) { return $s['is_active'] == 1; })) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
                        <div class="flex items-center">
                            <div class="bg-blue-100 p-3 rounded-full">
                                <i class="fas fa-layer-group text-blue-600 text-xl"></i>
                            </div>
                            <div class="mr-4">
                                <p class="text-sm text-gray-600">خيارات العلاج المرتبطة</p>
                                <p class="text-2xl font-bold text-gray-800"><?= count(array_unique(array_column($treatment_stages, 'treatment_options_code'))) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($treatment_stages)): ?>
                    <!-- Empty State -->
                    <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                        <div class="mb-6">
                            <div class="bg-gray-100 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-list-ol text-gray-400 text-3xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-800 mb-2">لا توجد مراحل علاج</h3>
                            <p class="text-gray-500 mb-6">ابدأ بإنشاء مراحل العلاج لتنظيم العمليات الطبية</p>
                        </div>

                        <!-- Setup Actions -->
                        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                            <button onclick="addTreatmentStage()" class="bg-purple-500 hover:bg-purple-600 text-white px-8 py-3 rounded-lg transition font-medium">
                                <i class="fas fa-plus ml-2"></i>
                                إضافة أول مرحلة علاج
                            </button>
                            <a href="update_treatment_stages_structure.php" target="_blank" class="bg-blue-500 hover:bg-blue-600 text-white px-8 py-3 rounded-lg transition font-medium">
                                <i class="fas fa-database ml-2"></i>
                                إنشاء بيانات تجريبية
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Treatment Stages Content -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <!-- Table Header -->
                        <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                            <div class="flex justify-between items-center">
                                <h3 class="text-lg font-semibold text-gray-800">
                                    قائمة مراحل العلاج (<?= count($treatment_stages) ?> مرحلة)
                                </h3>
                                <div class="flex gap-2">
                                    <button onclick="toggleViewMode('table')" id="tableViewBtn" class="px-3 py-2 text-sm bg-purple-100 text-purple-700 rounded-lg">
                                        <i class="fas fa-table ml-1"></i>
                                        جدول
                                    </button>
                                    <button onclick="toggleViewMode('cards')" id="cardsViewBtn" class="px-3 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg">
                                        <i class="fas fa-th-large ml-1"></i>
                                        بطاقات
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Table View -->
                        <div id="tableView" class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المرحلة</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">خيار العلاج</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الترتيب</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المدة</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($treatment_stages as $stage): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($stage['title_ar']) ?></div>
                                                <?php if (!empty($stage['description_ar'])): ?>
                                                <div class="text-sm text-gray-500"><?= htmlspecialchars(substr($stage['description_ar'], 0, 60)) ?><?= strlen($stage['description_ar']) > 60 ? '...' : '' ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($stage['option_name'] ?? 'غير محدد') ?></div>
                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($stage['treatment_type_name'] ?? 'غير محدد') ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                <?= $stage['stage_order'] ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?= htmlspecialchars($stage['duration_ar'] ?? 'غير محدد') ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $stage['is_active'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $stage['is_active'] == 1 ? 'نشط' : 'غير نشط' ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center space-x-2 space-x-reverse">
                                                <button onclick="editTreatmentStage(<?= $stage['id'] ?>, <?= $stage['treatment_options_code'] ? $stage['treatment_options_code'] : 'null' ?>, <?= $stage['stage_order'] ?>, '<?= htmlspecialchars($stage['title_ar'], ENT_QUOTES) ?>', '<?= htmlspecialchars($stage['description_ar'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($stage['duration_ar'] ?? '', ENT_QUOTES) ?>', <?= $stage['is_active'] ?>)" class="text-blue-600 hover:text-blue-900 p-2 rounded-lg hover:bg-blue-50 transition">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteTreatmentStage(<?= $stage['id'] ?>)" class="text-red-600 hover:text-red-900 p-2 rounded-lg hover:bg-red-50 transition">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Cards View (Hidden by default) -->
                        <div id="cardsView" class="p-6 hidden">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php foreach ($treatment_stages as $stage): ?>
                                <div class="bg-white border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow">
                                    <div class="flex justify-between items-start mb-4">
                                        <div class="flex items-center">
                                            <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                                المرحلة <?= $stage['stage_order'] ?>
                                            </span>
                                            <span class="mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $stage['is_active'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $stage['is_active'] == 1 ? 'نشط' : 'غير نشط' ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center space-x-1 space-x-reverse">
                                            <button onclick="editTreatmentStage(<?= $stage['id'] ?>, <?= $stage['treatment_options_code'] ? $stage['treatment_options_code'] : 'null' ?>, <?= $stage['stage_order'] ?>, '<?= htmlspecialchars($stage['title_ar'], ENT_QUOTES) ?>', '<?= htmlspecialchars($stage['description_ar'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($stage['duration_ar'] ?? '', ENT_QUOTES) ?>', <?= $stage['is_active'] ?>)" class="text-blue-600 hover:text-blue-800 p-1">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteTreatmentStage(<?= $stage['id'] ?>)" class="text-red-600 hover:text-red-800 p-1">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <h4 class="font-semibold text-gray-900 mb-2"><?= htmlspecialchars($stage['title_ar']) ?></h4>
                                    <?php if (!empty($stage['description_ar'])): ?>
                                    <p class="text-sm text-gray-600 mb-3"><?= htmlspecialchars($stage['description_ar']) ?></p>
                                    <?php endif; ?>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">خيار العلاج:</span>
                                            <span class="font-medium"><?= htmlspecialchars($stage['option_name'] ?? 'غير محدد') ?></span>
                                        </div>
                                        <?php if (!empty($stage['duration_ar'])): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">المدة:</span>
                                            <span class="font-medium"><?= htmlspecialchars($stage['duration_ar']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Treatment Option Modal -->
    <div id="treatmentOptionModal" class="modal">
        <div class="modal-content">
            <div class="bg-orange-600 text-white p-4 rounded-t-lg">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold" id="modalTitle">إضافة خيار علاج جديد</h3>
                    <button onclick="closeModal()" class="text-white hover:text-gray-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <form id="treatmentOptionForm" method="POST" class="p-6">
                <input type="hidden" name="action" id="formAction" value="add_treatment_option">
                <input type="hidden" name="original_treatment_type_code" id="originalTreatmentTypeCode">
                <input type="hidden" name="original_option_code" id="originalOptionCode">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                        <select name="treatment_type_code" id="treatmentTypeCode" required
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500">
                            <option value="">اختر نوع العلاج...</option>
                            <?php foreach ($treatment_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['code']) ?>"><?= htmlspecialchars($type['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">كود الخيار *</label>
                        <input type="text" name="option_code" id="optionCode" required
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                               placeholder="مثال: basic_cleaning">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">اسم الخيار *</label>
                    <input type="text" name="name_ar" id="nameAr" required
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                           placeholder="مثال: تنظيف أساسي">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">الوصف</label>
                    <textarea name="description_ar" id="descriptionAr" rows="3"
                              class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                              placeholder="وصف مفصل للخيار..."></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">السعر (ليرة سورية)</label>
                        <input type="number" name="price" id="price" step="0.01" min="0"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                               placeholder="0.00">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ترتيب العرض</label>
                        <input type="number" name="display_order" id="displayOrder" min="0"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                               placeholder="0">
                    </div>
                </div>

                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" id="isActive" checked
                               class="rounded border-gray-300 text-orange-600 shadow-sm focus:border-orange-300 focus:ring focus:ring-orange-200">
                        <span class="mr-2 text-gray-700">نشط</span>
                    </label>
                </div>

                <div class="flex justify-end space-x-4 space-x-reverse">
                    <button type="button" onclick="closeModal()"
                            class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        إلغاء
                    </button>
                    <button type="submit" class="px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition">
                        <span id="submitButtonText">إضافة</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Treatment Stage Modal -->
    <div id="treatmentStageModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center p-6 border-b">
                <h3 id="stageModalTitle" class="text-xl font-bold text-gray-800">إضافة مرحلة علاج جديدة</h3>
                <button type="button" onclick="closeStageModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="treatmentStageForm" method="POST" class="p-6">
                <input type="hidden" name="action" id="stageFormAction" value="add_treatment_stage">
                <input type="hidden" name="stage_id" id="originalStageId">

                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">خيار العلاج *</label>
                    <select name="treatment_options_code" id="stageTreatmentOptionsCode" required
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500">
                        <option value="">اختر خيار العلاج...</option>
                        <?php foreach ($treatment_options as $type_code => $options): ?>
                            <?php
                            $type_name = '';
                            foreach ($treatment_types as $type) {
                                if ($type['code'] === $type_code) {
                                    $type_name = $type['name_ar'];
                                    break;
                                }
                            }
                            ?>
                            <optgroup label="<?= htmlspecialchars($type_name) ?>">
                                <?php foreach ($options as $option): ?>
                                    <option value="<?= $option['id'] ?>"><?= htmlspecialchars($option['name_ar']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ترتيب المرحلة *</label>
                        <input type="number" name="stage_order" id="stageOrder" required min="1"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                               placeholder="1">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">المدة المتوقعة</label>
                        <input type="text" name="duration_ar" id="stageDurationAr"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                               placeholder="مثال: 30 دقيقة">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">عنوان المرحلة *</label>
                    <input type="text" name="title_ar" id="stageTitleAr" required
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                           placeholder="مثال: الفحص الأولي">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">وصف المرحلة</label>
                    <textarea name="description_ar" id="stageDescriptionAr" rows="3"
                              class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500"
                              placeholder="وصف مفصل لما يتم في هذه المرحلة..."></textarea>
                </div>

                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" id="stageIsActive" checked
                               class="rounded border-gray-300 text-orange-600 shadow-sm focus:border-orange-300 focus:ring focus:ring-orange-200">
                        <span class="mr-2 text-gray-700">نشط</span>
                    </label>
                </div>

                <div class="flex justify-end space-x-4 space-x-reverse">
                    <button type="button" onclick="closeStageModal()"
                            class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        إلغاء
                    </button>
                    <button type="submit" class="px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition">
                        <span id="stageSubmitButtonText">إضافة</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Switch between tabs
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
                button.classList.add('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
            });

            // Show selected tab content
            document.getElementById(tabName + '_content').classList.add('active');

            // Add active class to selected tab button
            event.target.classList.add('active');
            event.target.classList.remove('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');

            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Open add treatment option modal
        function openAddTreatmentOptionModal() {
            document.getElementById('modalTitle').textContent = 'إضافة خيار علاج جديد';
            document.getElementById('formAction').value = 'add_treatment_option';
            document.getElementById('submitButtonText').textContent = 'إضافة';

            // Clear form
            document.getElementById('treatmentOptionForm').reset();
            document.getElementById('isActive').checked = true;

            // Clear hidden fields
            document.getElementById('originalTreatmentTypeCode').value = '';
            document.getElementById('originalOptionCode').value = '';

            document.getElementById('treatmentOptionModal').style.display = 'block';
        }

        // Edit treatment option
        function editTreatmentOption(treatmentTypeCode, optionCode) {
            document.getElementById('modalTitle').textContent = 'تعديل خيار العلاج';
            document.getElementById('formAction').value = 'edit_treatment_option';
            document.getElementById('submitButtonText').textContent = 'تحديث';

            // Set original values for update
            document.getElementById('originalTreatmentTypeCode').value = treatmentTypeCode;
            document.getElementById('originalOptionCode').value = optionCode;

            // You would typically fetch the current values via AJAX here
            // For now, we'll just open the modal
            document.getElementById('treatmentOptionModal').style.display = 'block';
        }

        // Delete treatment option
        function deleteTreatmentOption(treatmentTypeCode, optionCode) {
            if (confirm('هل أنت متأكد من حذف خيار العلاج؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_treatment_option">
                    <input type="hidden" name="treatment_type_code" value="${treatmentTypeCode}">
                    <input type="hidden" name="option_code" value="${optionCode}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('treatmentOptionModal').style.display = 'none';
        }

        // Treatment Stage Functions
        function addTreatmentStage(treatmentOptionsCode = null) {
            document.getElementById('stageModalTitle').textContent = 'إضافة مرحلة علاج جديدة';
            document.getElementById('stageFormAction').value = 'add_treatment_stage';
            document.getElementById('stageSubmitButtonText').textContent = 'إضافة';

            // Clear form
            document.getElementById('treatmentStageForm').reset();

            // Pre-select treatment option if provided
            if (treatmentOptionsCode) {
                document.getElementById('stageTreatmentOptionsCode').value = treatmentOptionsCode;
            }

            document.getElementById('stageIsActive').checked = true;

            // Clear hidden fields
            document.getElementById('originalStageId').value = '';

            document.getElementById('treatmentStageModal').style.display = 'block';
        }

        // Edit treatment stage
        function editTreatmentStage(stageId, treatmentOptionsCode, stageOrder, titleAr, descriptionAr, durationAr, isActive) {
            document.getElementById('stageModalTitle').textContent = 'تعديل مرحلة العلاج';
            document.getElementById('stageFormAction').value = 'edit_treatment_stage';
            document.getElementById('stageSubmitButtonText').textContent = 'تحديث';

            // Set values
            document.getElementById('originalStageId').value = stageId;
            document.getElementById('stageTreatmentOptionsCode').value = treatmentOptionsCode;
            document.getElementById('stageOrder').value = stageOrder;
            document.getElementById('stageTitleAr').value = titleAr;
            document.getElementById('stageDescriptionAr').value = descriptionAr;
            document.getElementById('stageDurationAr').value = durationAr;
            document.getElementById('stageIsActive').checked = isActive == 1;

            document.getElementById('treatmentStageModal').style.display = 'block';
        }

        // Delete treatment stage
        function deleteTreatmentStage(stageId) {
            if (confirm('هل أنت متأكد من حذف مرحلة العلاج؟')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_treatment_stage">
                    <input type="hidden" name="stage_id" value="${stageId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close stage modal
        function closeStageModal() {
            document.getElementById('treatmentStageModal').style.display = 'none';
        }

        // Toggle view mode between table and cards
        function toggleViewMode(mode) {
            const tableView = document.getElementById('tableView');
            const cardsView = document.getElementById('cardsView');
            const tableBtn = document.getElementById('tableViewBtn');
            const cardsBtn = document.getElementById('cardsViewBtn');

            if (mode === 'table') {
                tableView.style.display = 'block';
                cardsView.style.display = 'none';
                tableBtn.className = 'px-3 py-2 text-sm bg-purple-100 text-purple-700 rounded-lg';
                cardsBtn.className = 'px-3 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg';
            } else {
                tableView.style.display = 'none';
                cardsView.style.display = 'block';
                tableBtn.className = 'px-3 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg';
                cardsBtn.className = 'px-3 py-2 text-sm bg-purple-100 text-purple-700 rounded-lg';
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('treatmentOptionModal');
            const stageModal = document.getElementById('treatmentStageModal');
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === stageModal) {
                closeStageModal();
            }
        }
    </script>
</body>
</html>