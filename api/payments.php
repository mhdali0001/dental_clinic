<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول
checkLogin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

try {
    switch ($method) {
        case 'GET':
            handleGetRequest();
            break;
        case 'POST':
            handlePostRequest();
            break;
        case 'PUT':
            handlePutRequest();
            break;
        case 'DELETE':
            handleDeleteRequest();
            break;
        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function handleGetRequest() {
    global $db;
    
    if (isset($_GET['patient_id'])) {
        // Get payments for specific patient
        $patientId = (int)$_GET['patient_id'];
        
        $query = "SELECT pay.*, t.treatment_type, t.treatment_date, u.full_name as created_by_name
                  FROM payments pay
                  LEFT JOIN treatments t ON pay.treatment_id = t.id
                  LEFT JOIN users u ON pay.created_by = u.id
                  WHERE pay.patient_id = ?
                  ORDER BY pay.payment_date DESC";
        
        $payments = $db->select($query, [$patientId]);
        
        echo json_encode([
            'success' => true,
            'payments' => $payments
        ]);
        
    } elseif (isset($_GET['treatment_id'])) {
        // Get payments for specific treatment
        $treatmentId = (int)$_GET['treatment_id'];
        
        $query = "SELECT pay.*, p.name as patient_name, u.full_name as created_by_name
                  FROM payments pay
                  JOIN patients p ON pay.patient_id = p.id
                  LEFT JOIN users u ON pay.created_by = u.id
                  WHERE pay.treatment_id = ?
                  ORDER BY pay.payment_date DESC";
        
        $payments = $db->select($query, [$treatmentId]);
        
        echo json_encode([
            'success' => true,
            'payments' => $payments
        ]);
        
    } elseif (isset($_GET['summary'])) {
        // Get payments summary
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
        $dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new Exception('تاريخ غير صحيح');
        }
        
        // Total payments in period
        $query = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_amount,
                    payment_method,
                    COUNT(*) as method_count
                  FROM payments 
                  WHERE DATE(payment_date) BETWEEN ? AND ?
                  GROUP BY payment_method";
        
        $payment_methods = $db->select($query, [$dateFrom, $dateTo]);
        
        // Daily payments
        $daily_query = "SELECT 
                          DATE(payment_date) as payment_day,
                          COUNT(*) as daily_count,
                          SUM(amount) as daily_amount
                        FROM payments 
                        WHERE DATE(payment_date) BETWEEN ? AND ?
                        GROUP BY DATE(payment_date)
                        ORDER BY payment_day DESC";
        
        $daily_payments = $db->select($daily_query, [$dateFrom, $dateTo]);
        
        // Total summary
        $total_query = "SELECT 
                          COUNT(*) as total_payments,
                          SUM(amount) as total_amount
                        FROM payments 
                        WHERE DATE(payment_date) BETWEEN ? AND ?";
        
        $total_summary = $db->selectOne($total_query, [$dateFrom, $dateTo]);
        
        echo json_encode([
            'success' => true,
            'summary' => [
                'total_payments' => $total_summary['total_payments'] ?? 0,
                'total_amount' => $total_summary['total_amount'] ?? 0,
                'payment_methods' => $payment_methods ?? [],
                'daily_payments' => $daily_payments ?? [],
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]
        ]);
        
    } else {
        // Get recent payments
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $query = "SELECT pay.*, p.name as patient_name, t.treatment_type, u.full_name as created_by_name
                  FROM payments pay
                  JOIN patients p ON pay.patient_id = p.id
                  LEFT JOIN treatments t ON pay.treatment_id = t.id
                  LEFT JOIN users u ON pay.created_by = u.id
                  ORDER BY pay.payment_date DESC, pay.id DESC
                  LIMIT ? OFFSET ?";
        
        $payments = $db->select($query, [$limit, $offset]);
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM payments";
        $total_result = $db->selectOne($count_query);
        
        echo json_encode([
            'success' => true,
            'payments' => $payments,
            'total' => $total_result['total'] ?? 0
        ]);
    }
}

function handlePostRequest() {
    // Add new payment
    $data = [
        'patient_id' => (int)$_POST['patient_id'],
        'treatment_id' => !empty($_POST['treatment_id']) ? (int)$_POST['treatment_id'] : null,
        'amount' => (float)$_POST['amount'],
        'payment_method' => sanitizeInput($_POST['payment_method']),
        'receipt_number' => sanitizeInput($_POST['receipt_number'] ?? ''),
        'notes' => sanitizeInput($_POST['notes'] ?? '')
    ];
    
    // Validation
    if (empty($data['patient_id']) || empty($data['amount']) || empty($data['payment_method'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    if ($data['amount'] <= 0) {
        throw new Exception('المبلغ يجب أن يكون أكبر من صفر');
    }
    
    $validMethods = ['cash', 'card', 'bank_transfer', 'insurance'];
    if (!in_array($data['payment_method'], $validMethods)) {
        throw new Exception('طريقة الدفع غير صحيحة');
    }
    
    // Check if patient exists
    global $db;
    $patient = $db->selectOne("SELECT id, name FROM patients WHERE id = ? AND status = 'active'", [$data['patient_id']]);
    if (!$patient) {
        throw new Exception('المريض غير موجود');
    }
    
    // Check if treatment exists (if provided)
    if ($data['treatment_id']) {
        $treatment = $db->selectOne("SELECT id, cost FROM treatments WHERE id = ? AND patient_id = ?", 
                                  [$data['treatment_id'], $data['patient_id']]);
        if (!$treatment) {
            throw new Exception('العلاج غير موجود أو لا ينتمي لهذا المريض');
        }
        
        // Check if payment exceeds remaining amount for this treatment
        $paid_query = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE treatment_id = ?";
        $paid_result = $db->selectOne($paid_query, [$data['treatment_id']]);
        $remaining = $treatment['cost'] - $paid_result['total_paid'];
        
        if ($data['amount'] > $remaining && $remaining > 0) {
            // Allow overpayment but warn
            // throw new Exception("المبلغ أكبر من المبلغ المتبقي للعلاج (" . number_format($remaining, 2) . " ليرة سورية)");
        }
    }
    
    // Add payment
    $query = "INSERT INTO payments (patient_id, treatment_id, amount, payment_method, receipt_number, notes, created_by) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $data['patient_id'], $data['treatment_id'], $data['amount'], 
        $data['payment_method'], $data['receipt_number'], $data['notes'], 
        $_SESSION['user_id']
    ];
    
    if ($db->execute($query, $params)) {
        $paymentId = $db->lastInsertId();
        
        // Update treatment payment status if specific treatment
        if ($data['treatment_id']) {
            updateTreatmentPaymentStatus($data['treatment_id']);
        }
        
        logActivity($_SESSION['user_id'], 'create', 'payments', $paymentId, 
                   "تم إضافة دفعة بمبلغ {$data['amount']} ليرة سورية للمريض {$patient['name']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم إضافة الدفعة بنجاح',
            'payment_id' => $paymentId
        ]);
    } else {
        throw new Exception('فشل في إضافة الدفعة');
    }
}

function handlePutRequest() {
    // Update payment
    parse_str(file_get_contents("php://input"), $putData);
    
    if (!isset($putData['id'])) {
        throw new Exception('معرف الدفعة مطلوب');
    }
    
    $paymentId = (int)$putData['id'];
    
    // Check if payment exists
    global $db;
    $payment = $db->selectOne("SELECT id, patient_id, treatment_id FROM payments WHERE id = ?", [$paymentId]);
    if (!$payment) {
        throw new Exception('الدفعة غير موجودة');
    }
    
    // Only allow updates if user is admin or created the payment
    $payment_full = $db->selectOne("SELECT created_by FROM payments WHERE id = ?", [$paymentId]);
    if ($_SESSION['user_role'] !== 'doctor' && $payment_full['created_by'] != $_SESSION['user_id']) {
        throw new Exception('غير مسموح لك بتعديل هذه الدفعة');
    }
    
    $data = [
        'amount' => (float)$putData['amount'],
        'payment_method' => sanitizeInput($putData['payment_method']),
        'receipt_number' => sanitizeInput($putData['receipt_number'] ?? ''),
        'notes' => sanitizeInput($putData['notes'] ?? '')
    ];
    
    // Validation
    if (empty($data['amount']) || empty($data['payment_method'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    if ($data['amount'] <= 0) {
        throw new Exception('المبلغ يجب أن يكون أكبر من صفر');
    }
    
    $validMethods = ['cash', 'card', 'bank_transfer', 'insurance'];
    if (!in_array($data['payment_method'], $validMethods)) {
        throw new Exception('طريقة الدفع غير صحيحة');
    }
    
    $query = "UPDATE payments SET 
              amount = ?, payment_method = ?, receipt_number = ?, notes = ?
              WHERE id = ?";
    
    $params = [$data['amount'], $data['payment_method'], $data['receipt_number'], $data['notes'], $paymentId];
    
    if ($db->execute($query, $params)) {
        // Update treatment payment status if linked to treatment
        if ($payment['treatment_id']) {
            updateTreatmentPaymentStatus($payment['treatment_id']);
        }
        
        logActivity($_SESSION['user_id'], 'update', 'payments', $paymentId, "تم تحديث الدفعة");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث الدفعة بنجاح'
        ]);
    } else {
        throw new Exception('فشل في تحديث الدفعة');
    }
}

function handleDeleteRequest() {
    // Delete payment
    parse_str(file_get_contents("php://input"), $deleteData);
    
    if (!isset($deleteData['id'])) {
        throw new Exception('معرف الدفعة مطلوب');
    }
    
    $paymentId = (int)$deleteData['id'];
    
    // Check if payment exists
    global $db;
    $payment = $db->selectOne("SELECT id, patient_id, treatment_id, amount FROM payments WHERE id = ?", [$paymentId]);
    if (!$payment) {
        throw new Exception('الدفعة غير موجودة');
    }
    
    // Only allow deletion if user is admin or created the payment
    $payment_full = $db->selectOne("SELECT created_by FROM payments WHERE id = ?", [$paymentId]);
    if ($_SESSION['user_role'] !== 'doctor' && $payment_full['created_by'] != $_SESSION['user_id']) {
        throw new Exception('غير مسموح لك بحذف هذه الدفعة');
    }
    
    // Get patient name for logging
    $patient = $db->selectOne("SELECT name FROM patients WHERE id = ?", [$payment['patient_id']]);
    
    // Delete payment
    $query = "DELETE FROM payments WHERE id = ?";
    
    if ($db->execute($query, [$paymentId])) {
        // Update treatment payment status if linked to treatment
        if ($payment['treatment_id']) {
            updateTreatmentPaymentStatus($payment['treatment_id']);
        }
        
        $patientName = $patient ? $patient['name'] : 'مجهول';
        logActivity($_SESSION['user_id'], 'delete', 'payments', $paymentId, 
                   "تم حذف دفعة بمبلغ {$payment['amount']} ليرة سورية للمريض $patientName");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم حذف الدفعة بنجاح'
        ]);
    } else {
        throw new Exception('فشل في حذف الدفعة');
    }
}

// Helper function to update treatment payment status
function updateTreatmentPaymentStatus($treatmentId) {
    global $db;
    
    $query = "SELECT t.cost,
                     COALESCE(SUM(pay.amount), 0) as total_paid
              FROM treatments t
              LEFT JOIN payments pay ON t.id = pay.treatment_id
              WHERE t.id = ?
              GROUP BY t.id, t.cost";
    
    $result = $db->selectOne($query, [$treatmentId]);
    
    if ($result) {
        $status = 'unpaid';
        if ($result['total_paid'] >= $result['cost']) {
            $status = 'paid';
        } elseif ($result['total_paid'] > 0) {
            $status = 'partial';
        }
        
        $update_query = "UPDATE treatments SET payment_status = ? WHERE id = ?";
        $db->execute($update_query, [$status, $treatmentId]);
    }
}
?>