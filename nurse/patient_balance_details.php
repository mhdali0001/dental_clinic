<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('nurse');

// Set page variables for header
$pageTitle = 'تفاصيل الرصيد';
$pageIcon = 'fas fa-wallet';
$pageSubtitle = '';
$currentPage = 'patient_balance';

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// التحقق من وجود معرف المريض
$patient_id = $_GET['id'] ?? 0;
if (!$patient_id) {
    header('Location: patient_balance.php');
    exit;
}

// جلب بيانات المريض
try {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND status = 'active'");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Location: patient_balance.php');
        exit;
    }
    // Update page subtitle with patient name
    $pageSubtitle = htmlspecialchars($patient['name']);
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patient = null;
}

// جلب تفاصيل العلاجات والدفعات
try {
    // جلب العلاجات مع إجمالي المدفوعات لكل علاج
    $stmt = $pdo->prepare("
        SELECT t.*, 
               COALESCE(SUM(pay.amount), 0) as total_paid,
               (t.cost - COALESCE(SUM(pay.amount), 0)) as remaining_amount
        FROM treatments t
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE t.patient_id = ? AND t.cost IS NOT NULL AND t.cost > 0
        GROUP BY t.id
        ORDER BY t.treatment_date DESC
    ");
    $stmt->execute([$patient_id]);
    $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // جلب جميع الدفعات للمريض
    $stmt = $pdo->prepare("
        SELECT pay.*, t.treatment_type, t.treatment_date, u.full_name as created_by_name
        FROM payments pay
        LEFT JOIN treatments t ON pay.treatment_id = t.id
        LEFT JOIN users u ON pay.created_by = u.id
        WHERE pay.patient_id = ?
        ORDER BY pay.payment_date DESC
    ");
    $stmt->execute([$patient_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // حساب الإحصائيات
    $total_cost = array_sum(array_column($treatments, 'cost'));
    $total_paid = array_sum(array_column($payments, 'amount'));
    $total_remaining = $total_cost - $total_paid;
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $treatments = [];
    $payments = [];
    $total_cost = $total_paid = $total_remaining = 0;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الرصيد - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .treatment-card { transition: all 0.3s ease; }
        .treatment-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .payment-row:hover { background-color: #f9fafb; }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/nurse_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <?php if (isset($error_message)): ?>
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
                <a href="patient_balance.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition">
                    العودة لقائمة رصيد المرضى
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
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="?action=add_payment&id=<?= $patient['id'] ?>" 
                       class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة دفعة
                    </a>
                    
                    <button onclick="window.print()" 
                            class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition flex items-center justify-center">
                        <i class="fas fa-print ml-2"></i>
                        طباعة التقرير
                    </button>
                </div>
            </div>
        </div>

        <!-- Balance Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 fade-in">
            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">إجمالي التكلفة</p>
                        <p class="text-3xl font-bold text-blue-600"><?= number_format($total_cost, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-chart-line text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">المبلغ المدفوع</p>
                        <p class="text-3xl font-bold text-green-600"><?= number_format($total_paid, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-money-bill-wave text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-lg shadow-lg p-6 border-r-4 border-<?= $total_remaining > 0 ? 'red' : ($total_remaining < 0 ? 'blue' : 'green') ?>-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">الرصيد المتبقي</p>
                        <p class="text-3xl font-bold text-<?= $total_remaining > 0 ? 'red' : ($total_remaining < 0 ? 'blue' : 'green') ?>-600">
                            <?= number_format($total_remaining, 2) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">ليرة سورية</p>
                    </div>
                    <div class="bg-<?= $total_remaining > 0 ? 'red' : ($total_remaining < 0 ? 'blue' : 'green') ?>-100 p-3 rounded-full">
                        <i class="fas fa-balance-scale text-<?= $total_remaining > 0 ? 'red' : ($total_remaining < 0 ? 'blue' : 'green') ?>-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Treatments List -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-file-medical text-blue-600 ml-2"></i>
                    العلاجات المدفوعة
                </h3>
                
                <?php if (empty($treatments)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-file-medical text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">لا توجد علاجات مدفوعة</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <?php foreach ($treatments as $treatment): ?>
                            <div class="treatment-card border rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex-1">
                                        <h5 class="font-semibold text-gray-800"><?= htmlspecialchars($treatment['treatment_type']) ?></h5>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-calendar ml-1"></i>
                                            <?= date('d/m/Y', strtotime($treatment['treatment_date'])) ?>
                                        </p>
                                    </div>
                                    <div class="text-left">
                                        <div class="text-sm">
                                            <span class="text-gray-600">التكلفة: </span>
                                            <span class="font-semibold text-blue-600"><?= number_format($treatment['cost'], 2) ?> ليرة سورية</span>
                                        </div>
                                        <div class="text-sm mt-1">
                                            <span class="text-gray-600">المدفوع: </span>
                                            <span class="font-semibold text-green-600"><?= number_format($treatment['total_paid'], 2) ?> ليرة سورية</span>
                                        </div>
                                        <div class="text-sm mt-1">
                                            <span class="text-gray-600">المتبقي: </span>
                                            <span class="font-semibold text-<?= $treatment['remaining_amount'] > 0 ? 'red' : 'green' ?>-600">
                                                <?= number_format($treatment['remaining_amount'], 2) ?> ليرة سورية
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Status Bar -->
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <?php 
                                    $percentage = $treatment['cost'] > 0 ? ($treatment['total_paid'] / $treatment['cost']) * 100 : 0;
                                    $percentage = min(100, $percentage);
                                    ?>
                                    <div class="bg-<?= $percentage >= 100 ? 'green' : 'blue' ?>-600 h-2 rounded-full" 
                                         style="width: <?= $percentage ?>%"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1 text-center">
                                    <?= number_format($percentage, 1) ?>% مدفوع
                                </p>
                                
                                <?php if ($treatment['diagnosis']): ?>
                                    <div class="mt-3 p-2 bg-gray-50 rounded text-sm">
                                        <strong>التشخيص:</strong> <?= htmlspecialchars($treatment['diagnosis']) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($treatment['payment_status'] !== 'paid'): ?>
                                    <div class="mt-3">
                                        <a href="?action=add_payment&id=<?= $patient['id'] ?>&treatment_id=<?= $treatment['id'] ?>" 
                                           class="w-full bg-green-500 hover:bg-green-600 text-white py-2 px-4 rounded text-sm transition text-center block">
                                            <i class="fas fa-plus ml-1"></i>
                                            دفع لهذا العلاج
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payments History -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-history text-green-600 ml-2"></i>
                    تاريخ الدفعات
                </h3>
                
                <?php if (empty($payments)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-money-bill text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">لا توجد دفعات مسجلة</p>
                    </div>
                <?php else: ?>
                    <div class="max-h-96 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="text-right py-2 px-3 font-medium text-gray-700">التاريخ</th>
                                    <th class="text-right py-2 px-3 font-medium text-gray-700">المبلغ</th>
                                    <th class="text-right py-2 px-3 font-medium text-gray-700">الطريقة</th>
                                    <th class="text-right py-2 px-3 font-medium text-gray-700">العلاج</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($payments as $payment): ?>
                                    <tr class="payment-row transition">
                                        <td class="py-3 px-3">
                                            <div class="text-gray-900 font-medium">
                                                <?= date('d/m/Y', strtotime($payment['payment_date'])) ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?= date('H:i', strtotime($payment['payment_date'])) ?>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <span class="font-semibold text-green-600">
                                                <?= number_format($payment['amount'], 2) ?> ليرة سورية
                                            </span>
                                        </td>
                                        <td class="py-3 px-3">
                                            <?php
                                            $methods = [
                                                'cash' => 'نقداً',
                                                'card' => 'بطاقة',
                                                'bank_transfer' => 'تحويل',
                                                'insurance' => 'تأمين'
                                            ];
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?= $methods[$payment['payment_method']] ?? $payment['payment_method'] ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3">
                                            <?php if ($payment['treatment_type']): ?>
                                                <div class="text-sm text-gray-900"><?= htmlspecialchars($payment['treatment_type']) ?></div>
                                                <div class="text-xs text-gray-500">
                                                    <?= date('d/m/Y', strtotime($payment['treatment_date'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-500 text-xs">دفعة عامة</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($payment['notes']): ?>
                                        <tr class="bg-gray-50">
                                            <td colspan="4" class="py-2 px-3">
                                                <div class="text-xs text-gray-600">
                                                    <i class="fas fa-sticky-note ml-1"></i>
                                                    <strong>ملاحظات:</strong> <?= htmlspecialchars($payment['notes']) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ($payment['receipt_number']): ?>
                                        <tr class="bg-gray-50">
                                            <td colspan="4" class="py-2 px-3">
                                                <div class="text-xs text-gray-600">
                                                    <i class="fas fa-receipt ml-1"></i>
                                                    <strong>رقم الإيصال:</strong> <?= htmlspecialchars($payment['receipt_number']) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Payments Summary -->
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-600">إجمالي الدفعات:</span>
                            <span class="font-semibold text-green-600"><?= count($payments) ?> دفعة</span>
                        </div>
                        <div class="flex justify-between items-center text-sm mt-1">
                            <span class="text-gray-600">إجمالي المبلغ:</span>
                            <span class="font-semibold text-green-600"><?= number_format($total_paid, 2) ?> ليرة سورية</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Balance Status Alert -->
        <?php if ($total_remaining != 0): ?>
            <div class="mt-8 fade-in">
                <?php if ($total_remaining > 0): ?>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                            </div>
                            <div class="mr-3 flex-1">
                                <h4 class="text-lg font-semibold text-red-800">مبلغ مستحق</h4>
                                <p class="text-red-700 mt-1">
                                    يوجد مبلغ مستحق قدره <strong><?= number_format($total_remaining, 2) ?> ليرة سورية</strong> على هذا المريض.
                                    يرجى التواصل معه لتحصيل المبلغ المستحق.
                                </p>
                                <div class="mt-4 flex space-x-4 space-x-reverse">
                                    <a href="tel:<?= $patient['phone'] ?>" 
                                       class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm transition">
                                        <i class="fas fa-phone ml-1"></i>
                                        اتصال للتذكير
                                    </a>
                                    <a href="?action=add_payment&id=<?= $patient['id'] ?>" 
                                       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition">
                                        <i class="fas fa-plus ml-1"></i>
                                        إضافة دفعة
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-blue-600 text-2xl"></i>
                            </div>
                            <div class="mr-3 flex-1">
                                <h4 class="text-lg font-semibold text-blue-800">دفع إضافي</h4>
                                <p class="text-blue-700 mt-1">
                                    دفع المريض مبلغ إضافي قدره <strong><?= number_format(abs($total_remaining), 2) ?> ليرة سورية</strong>.
                                    يمكن استخدام هذا المبلغ للعلاجات المستقبلية أو إرجاعه للمريض.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($total_cost > 0): ?>
            <div class="mt-8 fade-in">
                <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                        </div>
                        <div class="mr-3 flex-1">
                            <h4 class="text-lg font-semibold text-green-800">مسدد بالكامل</h4>
                            <p class="text-green-700 mt-1">
                                المريض مسدد جميع المبالغ المستحقة عليه. إجمالي المبلغ المسدد: <strong><?= number_format($total_paid, 2) ?> ليرة سورية</strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        // Print functionality
        window.addEventListener('beforeprint', function() {
            // Hide navigation and action buttons when printing
            document.querySelectorAll('nav, .no-print, header .flex .space-x-4').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Show navigation and action buttons after printing
            document.querySelectorAll('nav, .no-print, header .flex .space-x-4').forEach(el => {
                el.style.display = '';
            });
        });
    </script>
</body>
</html>