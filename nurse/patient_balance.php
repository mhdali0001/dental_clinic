<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin(['nurse', 'doctor']);

// Set page variables for header
$pageTitle = 'أرصدة المرضى';
$pageIcon = 'fas fa-wallet';
$pageSubtitle = 'إدارة الدفعات والحسابات';
$currentPage = 'patient_balance';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// إضافة دفعة جديدة
if ($_POST && $action === 'add_payment') {
    try {
        // كل دفعة مرتبطة بعلاج (العمود treatment_id في جدول payments إلزامي)
        $payment_treatment_id = (int)($_POST['treatment_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatments WHERE id = ? AND patient_id = ?");
        $stmt->execute([$payment_treatment_id, (int)($_POST['patient_id'] ?? 0)]);
        if (!$payment_treatment_id || !$stmt->fetchColumn()) {
            throw new InvalidArgumentException('يرجى اختيار العلاج الذي تُسجَّل عليه الدفعة');
        }

        $stmt = $pdo->prepare("
            INSERT INTO payments (patient_id, treatment_id, amount, payment_method, receipt_number, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $_POST['patient_id'],
            $payment_treatment_id,
            $_POST['amount'],
            $_POST['payment_method'],
            $_POST['receipt_number'] ?? '',
            $_POST['notes'] ?? '',
            $_SESSION['user_id']
        ]);
        
        if ($result) {
            // تحديث حالة الدفع للعلاج إذا تم تحديد علاج محدد
            if (!empty($_POST['treatment_id'])) {
                updateTreatmentPaymentStatus($_POST['treatment_id']);
            }
            
            $success_message = "تم إضافة الدفعة بنجاح";
            $action = ''; // إخفاء النموذج
        }
    } catch (InvalidArgumentException $e) {
        $error_message = $e->getMessage();
    } catch (PDOException $e) {
        $error_message = "خطأ في إضافة الدفعة: " . $e->getMessage();
    }
}

// البحث والفلترة
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// بناء الاستعلام
$where_conditions = ["p.status = 'active'"];
$params = [];

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'unpaid') {
    $where_conditions[] = "balance.total_cost > balance.total_paid";
} elseif ($filter === 'paid') {
    $where_conditions[] = "balance.total_cost <= balance.total_paid";
} elseif ($filter === 'no_treatments') {
    $where_conditions[] = "balance.total_cost IS NULL OR balance.total_cost = 0";
}

$where_clause = implode(' AND ', $where_conditions);

// جلب بيانات رصيد المرضى
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM patients p 
        LEFT JOIN (
            SELECT patient_id,
                   SUM(cost) as total_cost,
                   COUNT(*) as treatment_count
            FROM treatments 
            WHERE cost IS NOT NULL 
            GROUP BY patient_id
        ) balance ON p.id = balance.patient_id
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_patients = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.phone, p.age, p.gender,
               COALESCE(balance.total_cost, 0) as total_cost,
               COALESCE(balance.treatment_count, 0) as treatment_count,
               COALESCE(payments.total_paid, 0) as total_paid,
               (COALESCE(balance.total_cost, 0) - COALESCE(payments.total_paid, 0)) as remaining_balance,
               payments.last_payment_date
        FROM patients p 
        LEFT JOIN (
            SELECT patient_id,
                   SUM(cost) as total_cost,
                   COUNT(*) as treatment_count
            FROM treatments 
            WHERE cost IS NOT NULL 
            GROUP BY patient_id
        ) balance ON p.id = balance.patient_id
        LEFT JOIN (
            SELECT patient_id,
                   SUM(amount) as total_paid,
                   MAX(payment_date) as last_payment_date
            FROM payments 
            GROUP BY patient_id
        ) payments ON p.id = payments.patient_id
        WHERE $where_clause
        ORDER BY remaining_balance DESC, p.name 
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $patients_balance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_patients / $per_page);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patients_balance = [];
    $total_patients = 0;
    $total_pages = 0;
}

// إحصائيات الرصيد
try {
    $stmt = $pdo->query("
        SELECT 
            SUM(COALESCE(balance.total_cost, 0)) as total_revenue,
            SUM(COALESCE(payments.total_paid, 0)) as total_collected,
            SUM(COALESCE(balance.total_cost, 0) - COALESCE(payments.total_paid, 0)) as total_outstanding,
            COUNT(DISTINCT CASE WHEN balance.total_cost > payments.total_paid THEN p.id END) as patients_with_balance
        FROM patients p 
        LEFT JOIN (
            SELECT patient_id, SUM(cost) as total_cost
            FROM treatments WHERE cost IS NOT NULL GROUP BY patient_id
        ) balance ON p.id = balance.patient_id
        LEFT JOIN (
            SELECT patient_id, SUM(amount) as total_paid
            FROM payments GROUP BY patient_id
        ) payments ON p.id = payments.patient_id
        WHERE p.status = 'active'
    ");
    $balance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $balance_stats = [
        'total_revenue' => 0,
        'total_collected' => 0,
        'total_outstanding' => 0,
        'patients_with_balance' => 0
    ];
}

// تم نقل الدالة إلى ملف functions.php
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أرصدة المرضى - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .balance-card { transition: all 0.3s ease; }
        .balance-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .positive-balance { border-right: 4px solid #ef4444; }
        .zero-balance { border-right: 4px solid #22c55e; }
        .negative-balance { border-right: 4px solid #3b82f6; }
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
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">إجمالي الإيرادات</p>
                        <p class="text-3xl font-bold text-blue-600"><?= number_format($balance_stats['total_revenue'], 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المبلغ المُحصل</p>
                        <p class="text-3xl font-bold text-green-600"><?= number_format($balance_stats['total_collected'], 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المبلغ المستحق</p>
                        <p class="text-3xl font-bold text-red-600"><?= number_format($balance_stats['total_outstanding'], 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-red-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى لديهم رصيد</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $balance_stats['patients_with_balance'] ?></p>
                        <p class="text-xs text-gray-500 mt-1">مريض</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-users text-yellow-600 text-xl"></i>
                    </div>
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
                    
                    <!-- Filter Buttons -->
                    <div class="flex flex-wrap gap-2">
                        <a href="?" class="<?= !$filter ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            الكل
                        </a>
                        <a href="?filter=unpaid<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'unpaid' ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            عليهم مبالغ
                        </a>
                        <a href="?filter=paid<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'paid' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            مسددين
                        </a>
                        <a href="?filter=no_treatments<?= $search ? '&search=' . urlencode($search) : '' ?>" 
                           class="<?= $filter === 'no_treatments' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700' ?> px-4 py-2 rounded-lg text-sm transition">
                            بدون علاجات
                        </a>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-4">
                    <a href="?action=add_payment" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة دفعة
                    </a>
                    <button onclick="window.print()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Payment Form -->
        <?php if ($action === 'add_payment'): ?>
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-money-bill text-green-600 ml-2"></i>
                        إضافة دفعة جديدة
                    </h3>
                    <a href="?" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-2xl"></i>
                    </a>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add_payment">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Patient Selection -->
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 font-semibold mb-2">المريض *</label>
                            <select name="patient_id" required 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                    onchange="loadPatientTreatments(this.value)">
                                <option value="">اختر مريض...</option>
                                <?php
                                try {
                                    $patients_stmt = $pdo->query("SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name");
                                    $patients_list = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    $selected_patient_id = $_GET['patient_id'] ?? '';
                                    foreach ($patients_list as $patient): ?>
                                        <option value="<?= $patient['id'] ?>" <?= $selected_patient_id == $patient['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($patient['name']) ?> - <?= $patient['phone'] ?>
                                        </option>
                                    <?php endforeach;
                                } catch (PDOException $e) {
                                    echo '<option value="">خطأ في تحميل المرضى</option>';
                                }
                                ?>
                            </select>
                        </div>
                        
                        <!-- Treatment Selection -->
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 font-semibold mb-2">العلاج *</label>
                            <select name="treatment_id" id="treatmentSelect" required
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="">اختر المريض أولاً...</option>
                            </select>
                        </div>
                        
                        <!-- Amount -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">المبلغ *</label>
                            <input type="number" name="amount" step="0.01" min="0" required
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                   placeholder="0.00">
                        </div>
                        
                        <!-- Payment Method -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">طريقة الدفع *</label>
                            <select name="payment_method" required 
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                <option value="">اختر طريقة الدفع</option>
                                <option value="cash">نقداً</option>
                                <option value="card">بطاقة ائتمانية</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="insurance">تأمين</option>
                            </select>
                        </div>
                        
                        <!-- Receipt Number -->
                        <div>
                            <label class="block text-gray-700 font-semibold mb-2">رقم الإيصال</label>
                            <input type="text" name="receipt_number"
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                   placeholder="رقم الإيصال (اختياري)">
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">ملاحظات</label>
                        <textarea name="notes" rows="3"
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                  placeholder="أي ملاحظات إضافية..."></textarea>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex space-x-4 space-x-reverse pt-4">
                        <button type="submit" 
                                class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-save ml-2"></i>
                            إضافة الدفعة
                        </button>
                        <a href="?" 
                           class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Patients Balance List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list ml-2"></i>
                        رصيد المرضى
                    </h3>
                    <span class="text-sm text-gray-600">
                        عرض <?= count($patients_balance) ?> من أصل <?= number_format($total_patients) ?> مريض
                    </span>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($patients_balance)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-money-bill-wave text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد بيانات رصيد</h3>
                        <p class="text-gray-600 mb-6">
                            <?php if ($search || $filter): ?>
                                لم يتم العثور على مرضى تطابق معايير البحث.
                            <?php else: ?>
                                لا توجد بيانات رصيد متاحة.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($patients_balance as $patient): ?>
                        <?php
                        $remaining_balance = $patient['remaining_balance'];
                        $card_class = '';
                        if ($remaining_balance > 0) {
                            $card_class = 'positive-balance';
                        } elseif ($remaining_balance == 0 && $patient['total_cost'] > 0) {
                            $card_class = 'zero-balance';
                        } elseif ($remaining_balance < 0) {
                            $card_class = 'negative-balance';
                        }
                        ?>
                        <div class="balance-card p-6 hover:bg-gray-50 <?= $card_class ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-user text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                <?= htmlspecialchars($patient['name']) ?>
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
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-chart-line text-blue-500 ml-1"></i>
                                            <strong>إجمالي التكلفة:</strong>
                                            <span class="text-blue-600 font-semibold"><?= number_format($patient['total_cost'], 2) ?> ليرة سورية</span>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-money-bill-wave text-green-500 ml-1"></i>
                                            <strong>المبلغ المدفوع:</strong>
                                            <span class="text-green-600 font-semibold"><?= number_format($patient['total_paid'], 2) ?> ليرة سورية</span>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-balance-scale text-purple-500 ml-1"></i>
                                            <strong>الرصيد المتبقي:</strong>
                                            <span class="<?= $remaining_balance > 0 ? 'text-red-600' : ($remaining_balance < 0 ? 'text-blue-600' : 'text-green-600') ?> font-semibold">
                                                <?= number_format($remaining_balance, 2) ?> ليرة سورية
                                                <?php if ($remaining_balance > 0): ?>
                                                    <i class="fas fa-exclamation-triangle text-red-500 mr-1"></i>
                                                <?php elseif ($remaining_balance < 0): ?>
                                                    <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-file-medical text-orange-500 ml-1"></i>
                                            <strong>عدد العلاجات:</strong>
                                            <?= $patient['treatment_count'] ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($patient['last_payment_date']): ?>
                                        <div class="mt-4 p-3 bg-blue-50 border-r-4 border-blue-300 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-calendar ml-1"></i>
                                                <strong>آخر دفعة:</strong>
                                                <?= date('d/m/Y', strtotime($patient['last_payment_date'])) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($remaining_balance > 0): ?>
                                        <div class="mt-4 p-3 bg-red-50 border-r-4 border-red-300 rounded">
                                            <p class="text-sm text-red-800">
                                                <i class="fas fa-exclamation-triangle ml-1"></i>
                                                <strong>تنبيه:</strong>
                                                يوجد مبلغ مستحق قدره <?= number_format($remaining_balance, 2) ?> ليرة سورية
                                            </p>
                                        </div>
                                    <?php elseif ($remaining_balance < 0): ?>
                                        <div class="mt-4 p-3 bg-blue-50 border-r-4 border-blue-300 rounded">
                                            <p class="text-sm text-blue-800">
                                                <i class="fas fa-info-circle ml-1"></i>
                                                <strong>ملاحظة:</strong>
                                                دفع المريض مبلغ إضافي قدره <?= number_format(abs($remaining_balance), 2) ?> ليرة سورية
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 mr-4">
                                    <a href="patient_balance_details.php?id=<?= $patient['id'] ?>" 
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-eye ml-1"></i>
                                        تفاصيل الرصيد
                                    </a>
                                    
                                    <a href="?action=add_payment&patient_id=<?= $patient['id'] ?>"
                                       class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-plus ml-1"></i>
                                        إضافة دفعة
                                    </a>
                                    
                                    <a href="patient_details.php?id=<?= $patient['id'] ?>" 
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-user ml-1"></i>
                                        ملف المريض
                                    </a>
                                    
                                    <?php if ($remaining_balance > 0): ?>
                                        <a href="tel:<?= $patient['phone'] ?>" 
                                           class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                            <i class="fas fa-phone ml-1"></i>
                                            تذكير بالدفع
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
        
        // Load patient treatments for payment form
        const preselectedTreatmentId = <?= json_encode((string)($_GET['treatment_id'] ?? '')) ?>;

        async function loadPatientTreatments(patientId) {
            const treatmentSelect = document.getElementById('treatmentSelect');
            
            if (!patientId) {
                treatmentSelect.innerHTML = '<option value="">اختر المريض أولاً...</option>';
                return;
            }

            try {
                const response = await fetch(`../api/treatments.php?patient_id=${patientId}`);
                const data = await response.json();

                const escapeHtml = text => String(text ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
                let options = '';
                let billableIds = [];

                if (data.success && data.treatments) {
                    data.treatments.forEach(treatment => {
                        const cost = parseFloat(treatment.cost || 0);
                        const paid = parseFloat(treatment.total_paid || 0);
                        const remaining = cost - paid;

                        if (cost > 0) {
                            billableIds.push(String(treatment.id));
                            options += `<option value="${treatment.id}">
                                ${escapeHtml(treatment.treatment_details || treatment.treatment_type)} - ${escapeHtml(treatment.treatment_date)}
                                (التكلفة: ${Math.round(cost)} ليرة سورية، المتبقي: ${Math.round(remaining)} ليرة سورية)
                            </option>`;
                        }
                    });
                }

                treatmentSelect.innerHTML = billableIds.length
                    ? '<option value="">اختر العلاج...</option>' + options
                    : '<option value="">لا توجد علاجات بتكلفة لهذا المريض</option>';

                // العلاج المحدد في الرابط (زر "دفعة" من صفحات الطبيب)، أو العلاج الوحيد إن وُجد
                if (billableIds.includes(preselectedTreatmentId)) {
                    treatmentSelect.value = preselectedTreatmentId;
                } else if (billableIds.length === 1) {
                    treatmentSelect.value = billableIds[0];
                }

            } catch (error) {
                console.error('Error loading treatments:', error);
                treatmentSelect.innerHTML = '<option value="">خطأ في تحميل العلاجات</option>';
            }
        }
        
        // Form validation
        const form = document.querySelector('form[method="POST"]');
        form?.addEventListener('submit', function(e) {
            const patientId = this.querySelector('select[name="patient_id"]').value;
            const treatmentId = this.querySelector('select[name="treatment_id"]').value;
            const amount = this.querySelector('input[name="amount"]').value;
            const paymentMethod = this.querySelector('select[name="payment_method"]').value;

            if (!patientId || !treatmentId || !amount || !paymentMethod) {
                e.preventDefault();
                alert('يرجى ملء جميع الحقول المطلوبة');
                return;
            }
            
            if (parseFloat(amount) <= 0) {
                e.preventDefault();
                alert('المبلغ يجب أن يكون أكبر من صفر');
                return;
            }
        });
        
        // Auto-focus on patient select if form is visible
        const patientSelect = document.querySelector('select[name="patient_id"]');
        if (patientSelect) {
            patientSelect.focus();

            // Load treatments if a patient is pre-selected
            if (patientSelect.value) {
                loadPatientTreatments(patientSelect.value);
            }
        }
    </script>
</body>
</html>