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
    // Get current waiting list
    $waitingList = getWaitingList();
    
    echo json_encode([
        'success' => true,
        'waiting_list' => $waitingList
    ]);
}

function handlePostRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && $input['action'] === 'call') {
        // Call patient from waiting list
        $waitingId = (int)$input['waiting_id'];
        $patientId = (int)$input['patient_id'];
        
        if (empty($waitingId) || empty($patientId)) {
            throw new Exception('معرف قائمة الانتظار ومعرف المريض مطلوبان');
        }
        
        global $db;
        
        // Check if waiting list entry exists
        $waiting = $db->selectOne("SELECT id FROM waiting_list WHERE id = ? AND patient_id = ? AND status = 'waiting'", 
                                [$waitingId, $patientId]);
        if (!$waiting) {
            throw new Exception('المريض غير موجود في قائمة الانتظار');
        }
        
        // Update status to called
        if (updateWaitingListStatus($waitingId, 'called')) {
            logActivity($_SESSION['user_id'], 'update', 'waiting_list', $waitingId, "تم استدعاء المريض");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم استدعاء المريض بنجاح'
            ]);
        } else {
            throw new Exception('فشل في استدعاء المريض');
        }
        
    } elseif (isset($input['action']) && $input['action'] === 'add') {
        // Add patient to waiting list
        $patientId = (int)$input['patient_id'];
        $appointmentId = isset($input['appointment_id']) ? (int)$input['appointment_id'] : null;
        $priority = $input['priority'] ?? 'normal';
        
        if (empty($patientId)) {
            throw new Exception('معرف المريض مطلوب');
        }
        
        if (!in_array($priority, ['normal', 'urgent', 'emergency'])) {
            throw new Exception('أولوية غير صحيحة');
        }
        
        global $db;
        
        // Check if patient exists
        $patient = $db->selectOne("SELECT id, name FROM patients WHERE id = ? AND status = 'active'", [$patientId]);
        if (!$patient) {
            throw new Exception('المريض غير موجود');
        }
        
        // Check if patient is already in waiting list
        $existing = $db->selectOne("SELECT id FROM waiting_list WHERE patient_id = ? AND status = 'waiting'", [$patientId]);
        if ($existing) {
            throw new Exception('المريض موجود بالفعل في قائمة الانتظار');
        }
        
        if (addToWaitingList($patientId, $appointmentId, $priority)) {
            logActivity($_SESSION['user_id'], 'create', 'waiting_list', null, "تم إضافة المريض {$patient['name']} لقائمة الانتظار");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة المريض لقائمة الانتظار بنجاح'
            ]);
        } else {
            throw new Exception('فشل في إضافة المريض لقائمة الانتظار');
        }
        
    } else {
        throw new Exception('إجراء غير معروف');
    }
}

function handlePutRequest() {
    // Update waiting list entry
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id'])) {
        throw new Exception('معرف قائمة الانتظار مطلوب');
    }
    
    $waitingId = (int)$input['id'];
    
    global $db;
    
    // Check if waiting list entry exists
    $waiting = $db->selectOne("SELECT id, patient_id FROM waiting_list WHERE id = ?", [$waitingId]);
    if (!$waiting) {
        throw new Exception('عنصر قائمة الانتظار غير موجود');
    }
    
    if (isset($input['status'])) {
        // Update status
        $status = $input['status'];
        $validStatuses = ['waiting', 'called', 'completed'];
        
        if (!in_array($status, $validStatuses)) {
            throw new Exception('حالة غير صحيحة');
        }
        
        if (updateWaitingListStatus($waitingId, $status)) {
            logActivity($_SESSION['user_id'], 'update', 'waiting_list', $waitingId, "تم تحديث حالة قائمة الانتظار إلى: $status");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث الحالة بنجاح'
            ]);
        } else {
            throw new Exception('فشل في تحديث الحالة');
        }
        
    } elseif (isset($input['priority'])) {
        // Update priority
        $priority = $input['priority'];
        $validPriorities = ['normal', 'urgent', 'emergency'];
        
        if (!in_array($priority, $validPriorities)) {
            throw new Exception('أولوية غير صحيحة');
        }
        
        $query = "UPDATE waiting_list SET priority = ? WHERE id = ?";
        
        if ($db->execute($query, [$priority, $waitingId])) {
            logActivity($_SESSION['user_id'], 'update', 'waiting_list', $waitingId, "تم تحديث أولوية قائمة الانتظار إلى: $priority");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث الأولوية بنجاح'
            ]);
        } else {
            throw new Exception('فشل في تحديث الأولوية');
        }
        
    } else {
        throw new Exception('لا توجد بيانات للتحديث');
    }
}

function handleDeleteRequest() {
    // Remove from waiting list
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id'])) {
        throw new Exception('معرف قائمة الانتظار مطلوب');
    }
    
    $waitingId = (int)$input['id'];
    
    global $db;
    
    // Check if waiting list entry exists
    $waiting = $db->selectOne("SELECT id, patient_id FROM waiting_list WHERE id = ?", [$waitingId]);
    if (!$waiting) {
        throw new Exception('عنصر قائمة الانتظار غير موجود');
    }
    
    // Get patient name for logging
    $patient = $db->selectOne("SELECT name FROM patients WHERE id = ?", [$waiting['patient_id']]);
    
    // Delete from waiting list
    $query = "DELETE FROM waiting_list WHERE id = ?";
    
    if ($db->execute($query, [$waitingId])) {
        $patientName = $patient ? $patient['name'] : 'مجهول';
        logActivity($_SESSION['user_id'], 'delete', 'waiting_list', $waitingId, "تم حذف المريض $patientName من قائمة الانتظار");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم حذف المريض من قائمة الانتظار بنجاح'
        ]);
    } else {
        throw new Exception('فشل في حذف المريض من قائمة الانتظار');
    }
}
?>