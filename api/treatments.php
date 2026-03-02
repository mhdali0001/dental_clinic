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
        // Get treatments for specific patient
        $patientId = (int)$_GET['patient_id'];
        
        $treatments = getPatientTreatments($patientId);
        
        echo json_encode([
            'success' => true,
            'treatments' => $treatments
        ]);
        
    } elseif (isset($_GET['id'])) {
        // Get specific treatment
        $treatmentId = (int)$_GET['id'];
        
        $query = "SELECT t.*, p.name as patient_name, u.full_name as doctor_name 
                  FROM treatments t 
                  JOIN patients p ON t.patient_id = p.id 
                  JOIN users u ON t.doctor_id = u.id 
                  WHERE t.id = ?";
        
        $treatment = $db->selectOne($query, [$treatmentId]);
        
        if (!$treatment) {
            throw new Exception('العلاج غير موجود');
        }
        
        echo json_encode([
            'success' => true,
            'treatment' => $treatment
        ]);
        
    } else {
        // Get recent treatments
        $limit = (int)($_GET['limit'] ?? 50);
        $doctorOnly = isset($_GET['doctor_only']) && $_SESSION['user_role'] === 'doctor';
        
        $query = "SELECT t.*, p.name as patient_name, u.full_name as doctor_name 
                  FROM treatments t 
                  JOIN patients p ON t.patient_id = p.id 
                  JOIN users u ON t.doctor_id = u.id";
        
        $params = [];
        
        if ($doctorOnly) {
            $query .= " WHERE t.doctor_id = ?";
            $params[] = $_SESSION['user_id'];
        }
        
        $query .= " ORDER BY t.treatment_date DESC, t.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $treatments = $db->select($query, $params);
        
        echo json_encode([
            'success' => true,
            'treatments' => $treatments
        ]);
    }
}

function handlePostRequest() {
    // Add new treatment (doctors only)
    if ($_SESSION['user_role'] !== 'doctor') {
        throw new Exception('غير مسموح لك بإضافة العلاجات');
    }
    
    $data = [
        'patient_id' => (int)$_POST['patient_id'],
        'appointment_id' => isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null,
        'treatment_date' => sanitizeInput($_POST['treatment_date']),
        'treatment_type' => sanitizeInput($_POST['treatment_type']),
        'symptoms' => sanitizeInput($_POST['symptoms'] ?? ''),
        'diagnosis' => sanitizeInput($_POST['diagnosis']),
        'treatment_details' => sanitizeInput($_POST['treatment_details']),
        'medications' => sanitizeInput($_POST['medications'] ?? ''),
        'cost' => !empty($_POST['cost']) ? (float)$_POST['cost'] : null,
        'next_appointment_date' => !empty($_POST['next_appointment_date']) ? sanitizeInput($_POST['next_appointment_date']) : null,
        'notes' => sanitizeInput($_POST['notes'] ?? '')
    ];
    
    // Validation
    if (empty($data['patient_id']) || empty($data['treatment_date']) || 
        empty($data['treatment_type']) || empty($data['diagnosis']) || 
        empty($data['treatment_details'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['treatment_date'])) {
        throw new Exception('تاريخ العلاج غير صحيح');
    }
    
    // Validate next appointment date if provided
    if ($data['next_appointment_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['next_appointment_date'])) {
        throw new Exception('تاريخ الموعد القادم غير صحيح');
    }
    
    // Check if patient exists
    global $db;
    $patient = $db->selectOne("SELECT id, name FROM patients WHERE id = ? AND status = 'active'", [$data['patient_id']]);
    if (!$patient) {
        throw new Exception('المريض غير موجود');
    }
    
    // Check if appointment exists (if provided)
    if ($data['appointment_id']) {
        $appointment = $db->selectOne("SELECT id FROM appointments WHERE id = ? AND patient_id = ?", 
                                    [$data['appointment_id'], $data['patient_id']]);
        if (!$appointment) {
            throw new Exception('الموعد غير موجود أو لا ينتمي لهذا المريض');
        }
    }
    
    // Validate cost if provided
    if ($data['cost'] !== null && $data['cost'] < 0) {
        throw new Exception('التكلفة لا يمكن أن تكون سالبة');
    }
    
    $treatmentId = addTreatment($data);
    
    if ($treatmentId) {
        echo json_encode([
            'success' => true,
            'message' => 'تم حفظ العلاج بنجاح',
            'treatment_id' => $treatmentId
        ]);
    } else {
        throw new Exception('فشل في حفظ العلاج');
    }
}

function handlePutRequest() {
    // Update treatment (doctors only)
    if ($_SESSION['user_role'] !== 'doctor') {
        throw new Exception('غير مسموح لك بتعديل العلاجات');
    }
    
    parse_str(file_get_contents("php://input"), $putData);
    
    if (!isset($putData['id'])) {
        throw new Exception('معرف العلاج مطلوب');
    }
    
    $treatmentId = (int)$putData['id'];
    
    // Check if treatment exists and belongs to current doctor
    global $db;
    $treatment = $db->selectOne("SELECT id, doctor_id FROM treatments WHERE id = ?", [$treatmentId]);
    if (!$treatment) {
        throw new Exception('العلاج غير موجود');
    }
    
    if ($treatment['doctor_id'] != $_SESSION['user_id']) {
        throw new Exception('غير مسموح لك بتعديل هذا العلاج');
    }
    
    $data = [
        'treatment_date' => sanitizeInput($putData['treatment_date']),
        'treatment_type' => sanitizeInput($putData['treatment_type']),
        'symptoms' => sanitizeInput($putData['symptoms'] ?? ''),
        'diagnosis' => sanitizeInput($putData['diagnosis']),
        'treatment_details' => sanitizeInput($putData['treatment_details']),
        'medications' => sanitizeInput($putData['medications'] ?? ''),
        'cost' => !empty($putData['cost']) ? (float)$putData['cost'] : null,
        'next_appointment_date' => !empty($putData['next_appointment_date']) ? sanitizeInput($putData['next_appointment_date']) : null,
        'notes' => sanitizeInput($putData['notes'] ?? '')
    ];
    
    // Validation
    if (empty($data['treatment_date']) || empty($data['treatment_type']) || 
        empty($data['diagnosis']) || empty($data['treatment_details'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['treatment_date'])) {
        throw new Exception('تاريخ العلاج غير صحيح');
    }
    
    if ($data['next_appointment_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['next_appointment_date'])) {
        throw new Exception('تاريخ الموعد القادم غير صحيح');
    }
    
    if ($data['cost'] !== null && $data['cost'] < 0) {
        throw new Exception('التكلفة لا يمكن أن تكون سالبة');
    }
    
    $query = "UPDATE treatments SET 
              treatment_date = ?, treatment_type = ?, symptoms = ?, diagnosis = ?, 
              treatment_details = ?, medications = ?, cost = ?, next_appointment_date = ?, 
              notes = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?";
    
    $params = [
        $data['treatment_date'], $data['treatment_type'], $data['symptoms'], $data['diagnosis'],
        $data['treatment_details'], $data['medications'], $data['cost'], $data['next_appointment_date'],
        $data['notes'], $treatmentId
    ];
    
    if ($db->execute($query, $params)) {
        logActivity($_SESSION['user_id'], 'update', 'treatments', $treatmentId, "تم تحديث العلاج");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث العلاج بنجاح'
        ]);
    } else {
        throw new Exception('فشل في تحديث العلاج');
    }
}

function handleDeleteRequest() {
    // Delete treatment (doctors only)
    if ($_SESSION['user_role'] !== 'doctor') {
        throw new Exception('غير مسموح لك بحذف العلاجات');
    }
    
    parse_str(file_get_contents("php://input"), $deleteData);
    
    if (!isset($deleteData['id'])) {
        throw new Exception('معرف العلاج مطلوب');
    }
    
    $treatmentId = (int)$deleteData['id'];
    
    // Check if treatment exists and belongs to current doctor
    global $db;
    $treatment = $db->selectOne("SELECT id, doctor_id, patient_id FROM treatments WHERE id = ?", [$treatmentId]);
    if (!$treatment) {
        throw new Exception('العلاج غير موجود');
    }
    
    if ($treatment['doctor_id'] != $_SESSION['user_id']) {
        throw new Exception('غير مسموح لك بحذف هذا العلاج');
    }
    
    // Check if there are payments linked to this treatment
    $paymentsQuery = "SELECT COUNT(*) as count FROM payments WHERE treatment_id = ?";
    $paymentsResult = $db->selectOne($paymentsQuery, [$treatmentId]);
    
    if ($paymentsResult['count'] > 0) {
        throw new Exception('لا يمكن حذف العلاج لوجود مدفوعات مرتبطة به');
    }
    
    // Delete treatment
    $query = "DELETE FROM treatments WHERE id = ?";
    
    if ($db->execute($query, [$treatmentId])) {
        logActivity($_SESSION['user_id'], 'delete', 'treatments', $treatmentId, "تم حذف العلاج");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم حذف العلاج بنجاح'
        ]);
    } else {
        throw new Exception('فشل في حذف العلاج');
    }
}
?>