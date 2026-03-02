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
    
    if (isset($_GET['id']) && isset($_GET['details'])) {
        // Get patient details with treatments
        $patientId = (int)$_GET['id'];
        
        $patient = getPatientDetails($patientId);
        if (!$patient) {
            throw new Exception('المريض غير موجود');
        }
        
        $treatments = getPatientTreatments($patientId);
        
        echo json_encode([
            'success' => true,
            'patient' => $patient,
            'treatments' => $treatments
        ]);
        
    } elseif (isset($_GET['for_select'])) {
        // Get patients for select dropdown
        $query = "SELECT id, name, phone FROM patients WHERE status = 'active' ORDER BY name LIMIT 100";
        $patients = $db->select($query);
        
        echo json_encode([
            'success' => true,
            'patients' => $patients
        ]);
        
    } else {
        // Get patients list with pagination and search
        $search = $_GET['search'] ?? '';
        $page = (int)($_GET['page'] ?? 1);
        $perPage = 20;
        
        $result = getPatientsList($search, $page, $perPage);
        
        echo json_encode([
            'success' => true,
            'patients' => $result['patients'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'current_page' => $result['current_page']
        ]);
    }
}

function handlePostRequest() {
    // Add new patient
    $data = [
        'name' => sanitizeInput($_POST['name']),
        'phone' => sanitizeInput($_POST['phone']),
        'age' => (int)$_POST['age'],
        'gender' => sanitizeInput($_POST['gender']),
        'address' => sanitizeInput($_POST['address'] ?? ''),
        'email' => sanitizeInput($_POST['email'] ?? ''),
        'emergency_contact' => sanitizeInput($_POST['emergency_contact'] ?? ''),
        'medical_history' => sanitizeInput($_POST['medical_history'] ?? ''),
        'allergies' => sanitizeInput($_POST['allergies'] ?? ''),
        'blood_type' => sanitizeInput($_POST['blood_type'] ?? '')
    ];
    
    // Validation
    if (empty($data['name']) || empty($data['phone']) || empty($data['age']) || empty($data['gender'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    if ($data['age'] < 1 || $data['age'] > 150) {
        throw new Exception('العمر غير صحيح');
    }
    
    if (!in_array($data['gender'], ['male', 'female'])) {
        throw new Exception('الجنس غير صحيح');
    }
    
    if (!empty($data['email']) && !validateEmail($data['email'])) {
        throw new Exception('البريد الإلكتروني غير صحيح');
    }
    
    // Check if phone already exists
    global $db;
    $existingQuery = "SELECT id FROM patients WHERE phone = ? AND status = 'active'";
    $existing = $db->selectOne($existingQuery, [$data['phone']]);
    
    if ($existing) {
        throw new Exception('رقم الهاتف مسجل مسبقاً');
    }
    
    $patientId = addPatient($data);
    
    if ($patientId) {
        echo json_encode([
            'success' => true,
            'message' => 'تم إضافة المريض بنجاح',
            'patient_id' => $patientId
        ]);
    } else {
        throw new Exception('فشل في إضافة المريض');
    }
}

function handlePutRequest() {
    // Update patient
    parse_str(file_get_contents("php://input"), $putData);
    
    if (!isset($putData['id'])) {
        throw new Exception('معرف المريض مطلوب');
    }
    
    $patientId = (int)$putData['id'];
    
    // Check if patient exists
    global $db;
    $patient = $db->selectOne("SELECT id FROM patients WHERE id = ?", [$patientId]);
    if (!$patient) {
        throw new Exception('المريض غير موجود');
    }
    
    $data = [
        'name' => sanitizeInput($putData['name']),
        'phone' => sanitizeInput($putData['phone']),
        'age' => (int)$putData['age'],
        'gender' => sanitizeInput($putData['gender']),
        'address' => sanitizeInput($putData['address'] ?? ''),
        'email' => sanitizeInput($putData['email'] ?? ''),
        'emergency_contact' => sanitizeInput($putData['emergency_contact'] ?? ''),
        'medical_history' => sanitizeInput($putData['medical_history'] ?? ''),
        'allergies' => sanitizeInput($putData['allergies'] ?? ''),
        'blood_type' => sanitizeInput($putData['blood_type'] ?? '')
    ];
    
    // Validation
    if (empty($data['name']) || empty($data['phone']) || empty($data['age']) || empty($data['gender'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    if (!empty($data['email']) && !validateEmail($data['email'])) {
        throw new Exception('البريد الإلكتروني غير صحيح');
    }
    
    // Check if phone already exists (excluding current patient)
    $existingQuery = "SELECT id FROM patients WHERE phone = ? AND id != ? AND status = 'active'";
    $existing = $db->selectOne($existingQuery, [$data['phone'], $patientId]);
    
    if ($existing) {
        throw new Exception('رقم الهاتف مسجل مسبقاً');
    }
    
    $query = "UPDATE patients SET 
              name = ?, phone = ?, age = ?, gender = ?, address = ?, 
              email = ?, emergency_contact = ?, medical_history = ?, 
              allergies = ?, blood_type = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?";
    
    $params = [
        $data['name'], $data['phone'], $data['age'], $data['gender'], 
        $data['address'], $data['email'], $data['emergency_contact'], 
        $data['medical_history'], $data['allergies'], $data['blood_type'], 
        $patientId
    ];
    
    if ($db->execute($query, $params)) {
        logActivity($_SESSION['user_id'], 'update', 'patients', $patientId, "تم تحديث بيانات المريض: {$data['name']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث بيانات المريض بنجاح'
        ]);
    } else {
        throw new Exception('فشل في تحديث بيانات المريض');
    }
}

function handleDeleteRequest() {
    // Soft delete patient
    parse_str(file_get_contents("php://input"), $deleteData);
    
    if (!isset($deleteData['id'])) {
        throw new Exception('معرف المريض مطلوب');
    }
    
    $patientId = (int)$deleteData['id'];
    
    // Check if patient exists
    global $db;
    $patient = $db->selectOne("SELECT id, name FROM patients WHERE id = ?", [$patientId]);
    if (!$patient) {
        throw new Exception('المريض غير موجود');
    }
    
    // Check if patient has active appointments
    $appointmentsQuery = "SELECT COUNT(*) as count FROM appointments 
                         WHERE patient_id = ? AND appointment_date >= CURDATE() 
                         AND status IN ('scheduled', 'confirmed')";
    $appointmentsResult = $db->selectOne($appointmentsQuery, [$patientId]);
    
    if ($appointmentsResult['count'] > 0) {
        throw new Exception('لا يمكن حذف المريض لوجود مواعيد مستقبلية');
    }
    
    // Soft delete
    $query = "UPDATE patients SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    
    if ($db->execute($query, [$patientId])) {
        logActivity($_SESSION['user_id'], 'delete', 'patients', $patientId, "تم حذف المريض: {$patient['name']}");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم حذف المريض بنجاح'
        ]);
    } else {
        throw new Exception('فشل في حذف المريض');
    }
}
?>