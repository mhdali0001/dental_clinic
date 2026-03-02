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

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add':
                // إضافة طبيب جديد
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, is_active, created_at)
                    VALUES (?, ?, ?, 'doctor', 1, NOW())
                ");

                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt->execute([
                    $_POST['username'],
                    $hashed_password,
                    $_POST['full_name']
                ]);

                $success_message = "تم إضافة الطبيب بنجاح";
                break;

            case 'edit':
                // تعديل بيانات الطبيب
                if (!empty($_POST['password'])) {
                    // تعديل مع كلمة مرور جديدة
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = ?, password = ?, full_name = ?
                        WHERE id = ? AND role = 'doctor'
                    ");
                    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt->execute([
                        $_POST['username'],
                        $hashed_password,
                        $_POST['full_name'],
                        $_POST['doctor_id']
                    ]);
                } else {
                    // تعديل بدون كلمة مرور
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET username = ?, full_name = ?
                        WHERE id = ? AND role = 'doctor'
                    ");
                    $stmt->execute([
                        $_POST['username'],
                        $_POST['full_name'],
                        $_POST['doctor_id']
                    ]);
                }

                $success_message = "تم تحديث بيانات الطبيب بنجاح";
                break;

            case 'toggle_status':
                // تفعيل/إلغاء تفعيل الطبيب
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET is_active = IF(is_active = 1, 0, 1)
                    WHERE id = ? AND role = 'doctor'
                ");
                $stmt->execute([$_POST['doctor_id']]);

                $success_message = "تم تحديث حالة الطبيب بنجاح";
                break;

            case 'delete':
                // حذف الطبيب (تعطيل فقط)
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET is_active = 0, deleted_at = NOW()
                    WHERE id = ? AND role = 'doctor'
                ");
                $stmt->execute([$_POST['doctor_id']]);

                $success_message = "تم حذف الطبيب بنجاح";
                break;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في العملية: " . $e->getMessage();
    }
}

// جلب قائمة الأطباء
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'all';

$where_conditions = ["role = 'doctor'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param]);
}

if ($status !== 'all') {
    $where_conditions[] = "is_active = ?";
    $params[] = ($status === 'active') ? 1 : 0;
}

$where_clause = implode(' AND ', $where_conditions);

try {
    $stmt = $pdo->prepare("
        SELECT
            id, username, full_name, is_active, created_at,
            (SELECT COUNT(*) FROM treatments WHERE doctor_id = users.id) as total_treatments,
            (SELECT COUNT(DISTINCT patient_id) FROM treatments WHERE doctor_id = users.id) as total_patients
        FROM users
        WHERE $where_clause
        ORDER BY full_name ASC
    ");
    $stmt->execute($params);
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "خطأ في جلب بيانات الأطباء: " . $e->getMessage();
    $doctors = [];
}

// إحصائيات الأطباء
try {
    $stats = $pdo->query("
        SELECT
            COUNT(*) as total_doctors,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_doctors,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_doctors,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as new_today
        FROM users
        WHERE role = 'doctor'
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_doctors' => 0, 'active_doctors' => 0, 'inactive_doctors' => 0, 'new_today' => 0];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الأطباء - إدارة العيادة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .doctor-card {
            transition: all 0.3s ease;
        }
        .doctor-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .modal {
            display: none;
        }
        .modal.active {
            display: flex;
        }
        .status-badge {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php
    $pageTitle = 'إدارة الأطباء';
    $pageIcon = 'fas fa-user-md';
    $pageSubtitle = 'إدارة وتنظيم حسابات الأطباء';
    include 'includes/admin_header.php';
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">إدارة الأطباء</h1>
                    <p class="mt-2 text-gray-600">إدارة شاملة لحسابات الأطباء</p>
                </div>
                <button onclick="openModal('addDoctorModal')" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium transition-all duration-200 shadow-lg hover:shadow-xl">
                    <i class="fas fa-plus ml-2"></i>
                    إضافة طبيب جديد
                </button>
            </div>
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

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-lg p-6 doctor-card">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">إجمالي الأطباء</p>
                        <p class="text-2xl font-bold text-gray-900"><?= $stats['total_doctors'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 doctor-card">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">الأطباء النشطون</p>
                        <p class="text-2xl font-bold text-green-600"><?= $stats['active_doctors'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 doctor-card">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-red-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-times-circle text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">الأطباء المعطلون</p>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['inactive_doctors'] ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 doctor-card">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-plus text-white text-xl"></i>
                        </div>
                    </div>
                    <div class="mr-4">
                        <p class="text-sm font-medium text-gray-600">جدد اليوم</p>
                        <p class="text-2xl font-bold text-purple-600"><?= $stats['new_today'] ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">البحث</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="ابحث بالاسم أو اسم المستخدم"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">الحالة</label>
                    <select name="status" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>جميع الأطباء</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>نشط</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>معطل</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg font-medium">
                        <i class="fas fa-search ml-1"></i>
                        بحث
                    </button>
                </div>
            </form>
        </div>

        <!-- Doctors Table -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-list text-blue-600 ml-2"></i>
                    قائمة الأطباء (<?= count($doctors) ?>)
                </h3>
            </div>

            <?php if (empty($doctors)): ?>
                <div class="p-12 text-center">
                    <i class="fas fa-user-md text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">لا توجد أطباء</h3>
                    <p class="text-gray-500">لم يتم العثور على أطباء مطابقين للبحث</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الطبيب</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإحصائيات</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإنضمام</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($doctors as $doctor): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-12 w-12">
                                                <div class="h-12 w-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center">
                                                    <span class="text-white font-bold text-lg">
                                                        <?= mb_substr($doctor['full_name'], 0, 1) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="mr-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($doctor['full_name']) ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    @<?= htmlspecialchars($doctor['username']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div class="space-y-1">
                                            <div class="flex items-center">
                                                <i class="fas fa-users text-blue-500 ml-1 text-xs"></i>
                                                <span><?= $doctor['total_patients'] ?> مريض</span>
                                            </div>
                                            <div class="flex items-center">
                                                <i class="fas fa-medical-kit text-green-500 ml-1 text-xs"></i>
                                                <span><?= $doctor['total_treatments'] ?> علاج</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($doctor['is_active']): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 status-badge">
                                                <i class="fas fa-check-circle ml-1"></i>
                                                نشط
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle ml-1"></i>
                                                معطل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('Y/m/d', strtotime($doctor['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="editDoctor(<?= htmlspecialchars(json_encode($doctor)) ?>)"
                                                    class="text-blue-600 hover:text-blue-900 p-2 rounded-lg hover:bg-blue-50 transition-colors">
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <form method="POST" class="inline" onsubmit="return confirm('هل أنت متأكد من تغيير حالة هذا الطبيب؟')">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="doctor_id" value="<?= $doctor['id'] ?>">
                                                <button type="submit" class="text-yellow-600 hover:text-yellow-900 p-2 rounded-lg hover:bg-yellow-50 transition-colors">
                                                    <i class="fas fa-toggle-<?= $doctor['is_active'] ? 'on' : 'off' ?>"></i>
                                                </button>
                                            </form>

                                            <form method="POST" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذا الطبيب؟ سيتم إلغاء تفعيل الحساب فقط.')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doctor_id" value="<?= $doctor['id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900 p-2 rounded-lg hover:bg-red-50 transition-colors">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Doctor Modal -->
    <div id="addDoctorModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 items-center justify-center">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-lg bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900">إضافة طبيب جديد</h3>
                    <button onclick="closeModal('addDoctorModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add">

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">الاسم الكامل</label>
                            <input type="text" name="full_name" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">اسم المستخدم</label>
                            <input type="text" name="username" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">كلمة المرور</label>
                            <input type="password" name="password" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="flex justify-end space-x-4 pt-4">
                        <button type="button" onclick="closeModal('addDoctorModal')"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                            إلغاء
                        </button>
                        <button type="submit"
                                class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                            <i class="fas fa-plus ml-1"></i>
                            إضافة الطبيب
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Doctor Modal -->
    <div id="editDoctorModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 items-center justify-center">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-lg bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900">تعديل بيانات الطبيب</h3>
                    <button onclick="closeModal('editDoctorModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4" id="editDoctorForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="doctor_id" id="edit_doctor_id">

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">الاسم الكامل</label>
                            <input type="text" name="full_name" id="edit_full_name" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">اسم المستخدم</label>
                            <input type="text" name="username" id="edit_username" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">كلمة المرور الجديدة (اختياري)</label>
                            <input type="password" name="password" id="edit_password"
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="اتركه فارغاً إذا لم ترد تغيير كلمة المرور">
                        </div>
                    </div>

                    <div class="flex justify-end space-x-4 pt-4">
                        <button type="button" onclick="closeModal('editDoctorModal')"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                            إلغاء
                        </button>
                        <button type="submit"
                                class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                            <i class="fas fa-save ml-1"></i>
                            حفظ التغييرات
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function editDoctor(doctor) {
            document.getElementById('edit_doctor_id').value = doctor.id;
            document.getElementById('edit_full_name').value = doctor.full_name;
            document.getElementById('edit_username').value = doctor.username;
            document.getElementById('edit_password').value = '';

            openModal('editDoctorModal');
        }

        // إغلاق النافذة المنبثقة عند النقر خارجها
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        }

        // تحديث الصفحة تلقائياً كل 30 ثانية لعرض آخر الإحصائيات
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>