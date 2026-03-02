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
    
    if (isset($_GET['date']) && $_GET['date'] === 'today') {
        // Get today's appointments
        $appointments = getTodayAppointments();
        
        echo json_encode([
            'success' => true,
            'appointments' => $appointments
        ]);
        
    } elseif (isset($_GET['date'])) {
        // Get appointments for specific date
        $date = $_GET['date'];
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('تاريخ غير صحيح');
        }
        
        $query = "SELECT a.*, p.name as patient_name, p.phone as patient_phone
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  WHERE a.appointment_date = ? 
                  ORDER BY a.appointment_time";
        
        $appointments = $db->select($query, [$date]);
        
        echo json_encode([
            'success' => true,
            'appointments' => $appointments
        ]);
        
    } elseif (isset($_GET['patient_id'])) {
        // Get appointments for specific patient
        $patientId = (int)$_GET['patient_id'];
        
        $query = "SELECT a.*, p.name as patient_name 
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  WHERE a.patient_id = ? 
                  ORDER BY a.appointment_date DESC, a.appointment_time DESC";
        
        $appointments = $db->select($query, [$patientId]);
        
        echo json_encode([
            'success' => true,
            'appointments' => $appointments
        ]);
        
    } else {
        // Get upcoming appointments
        $limit = (int)($_GET['limit'] ?? 20);
        
        $query = "SELECT a.*, p.name as patient_name, p.phone as patient_phone
                  FROM appointments a 
                  JOIN patients p ON a.patient_id = p.id 
                  WHERE a.appointment_date >= CURDATE() 
                  AND a.status IN ('scheduled', 'confirmed') 
                  ORDER BY a.appointment_date, a.appointment_time 
                  LIMIT ?";
        
        $appointments = $db->select($query, [$limit]);
        
        echo json_encode([
            'success' => true,
            'appointments' => $appointments
        ]);
    }
}

function handlePostRequest() {
    // Add new appointment
    $data = [
        'patient_id' => (int)$_POST['patient_id'],
        'appointment_date' => sanitizeInput($_POST['appointment_date']),
        'appointment_time' => sanitizeInput($_POST['appointment_time']),
        'treatment_type' => sanitizeInput($_POST['treatment_type']),
        'estimated_duration' => (int)($_POST['estimated_duration'] ?? 30),
        'notes' => sanitizeInput($_POST['notes'] ?? '')
    ];
    
    // Validation
    if (empty($data['patient_id']) || empty($data['appointment_date']) || 
        empty($data['appointment_time']) || empty($data['treatment_type'])) {
        throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['appointment_date'])) {
        throw new Exception('تاريخ غير صحيح');
    }
    
    // Validate time format
    if (!preg_match('/^\d{2}:\d{2}$/', $data['appointment_time'])) {
        throw new Exception('وقت غير صحيح');
    }
    
    // Check if patient exists
    global $db;
    $patient = $db->selectOne("SELECT id, name FROM patients WHERE id = ? AND status = 'active'", [$data['patient_id']]);
    if (!$patient) {
        throw new Exception('المريض غير موجود');
    }
    
    // Check for appointment conflicts
    if (checkAppointmentConflict($data['appointment_date'], $data['appointment_time'], $data['estimated_duration'])) {
        throw new Exception('يوجد تعارض مع موعد آخر في نفس الوقت');
    }
    
    // Check if appointment is in the past (except for today)
    $appointmentDateTime = $data['appointment_date'] . ' ' . $data['appointment_time'];
    if (strtotime($appointmentDateTime) < strtotime('today')) {
        throw new Exception('لا يمكن حجز موعد في الماضي');
    }
    
    $appointmentId = addAppointment($data);
    
    if ($appointmentId) {
        echo json_encode([
            'success' => true,
            'message' => 'تم حجز الموعد بنجاح',
            'appointment_id' => $appointmentId
        ]);
    } else {
        throw new Exception('فشل في حجز الموعد');
    }
}

function handlePutRequest() {
    // Update appointment
    parse_str(file_get_contents("php://input"), $putData);
    
    if (!isset($putData['id'])) {
        throw new Exception('معرف الموعد مطلوب');
    }
    
    $appointmentId = (int)$putData['id'];
    
    // Check if appointment exists
    global $db;
    $appointment = $db->selectOne("SELECT id FROM appointments WHERE id = ?", [$appointmentId]);
    if (!$appointment) {
        throw new Exception('الموعد غير موجود');
    }
    
    if (isset($putData['status'])) {
        // Update appointment status only
        $status = sanitizeInput($putData['status']);
        $validStatuses = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];
        
        if (!in_array($status, $validStatuses)) {
            throw new Exception('حالة غير صحيحة');
        }
        
        $query = "UPDATE appointments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        
        if ($db->execute($query, [$status, $appointmentId])) {
            logActivity($_SESSION['user_id'], 'update', 'appointments', $appointmentId, "تم تحديث حالة الموعد إلى: $status");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث حالة الموعد بنجاح'
            ]);
        } else {
            throw new Exception('فشل في تحديث حالة الموعد');
        }
    } else {
        // Full appointment update
        $data = [
            'patient_id' => (int)$putData['patient_id'],
            'appointment_date' => sanitizeInput($putData['appointment_date']),
            'appointment_time' => sanitizeInput($putData['appointment_time']),
            'treatment_type' => sanitizeInput($putData['treatment_type']),
            'estimated_duration' => (int)($putData['estimated_duration'] ?? 30),
            'notes' => sanitizeInput($putData['notes'] ?? '')
        ];
        
        // Validation
        if (empty($data['patient_id']) || empty($data['appointment_date']) || 
            empty($data['appointment_time']) || empty($data['treatment_type'])) {
            throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
        }
        
        // Check for conflicts (excluding current appointment)
        if (checkAppointmentConflict($data['appointment_date'], $data['appointment_time'], $data['estimated_duration'], $appointmentId)) {
            throw new Exception('يوجد تعارض مع موعد آخر في نفس الوقت');
        }
        
        $query = "UPDATE appointments SET 
                  patient_id = ?, appointment_date = ?, appointment_time = ?, 
                  treatment_type = ?, estimated_duration = ?, notes = ?, 
                  updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?";
        
        $params = [
            $data['patient_id'], $data['appointment_date'], $data['appointment_time'],
            $data['treatment_type'], $data['estimated_duration'], $data['notes'],
            $appointmentId
        ];
        
        if ($db->execute($query, $params)) {
            logActivity($_SESSION['user_id'], 'update', 'appointments', $appointmentId, "تم تحديث الموعد");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث الموعد بنجاح'
            ]);
        } else {
            throw new Exception('فشل في تحديث الموعد');
        }
    }
}

function handleDeleteRequest() {
    // Cancel/Delete appointment
    parse_str(file_get_contents("php://input"), $deleteData);
    
    if (!isset($deleteData['id'])) {
        throw new Exception('معرف الموعد مطلوب');
    }
    
    $appointmentId = (int)$deleteData['id'];
    
    // Check if appointment exists
    global $db;
    $appointment = $db->selectOne("SELECT id, patient_id, appointment_date, appointment_time FROM appointments WHERE id = ?", [$appointmentId]);
    if (!$appointment) {
        throw new Exception('الموعد غير موجود');
    }
    
    // Check if appointment can be cancelled (not in the past and not completed)
    $appointmentDateTime = $appointment['appointment_date'] . ' ' . $appointment['appointment_time'];
    if (strtotime($appointmentDateTime) < strtotime('now')) {
        throw new Exception('لا يمكن إلغاء موعد قد انتهى');
    }
    
    // Update appointment status to cancelled
    $query = "UPDATE appointments SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    
    if ($db->execute($query, [$appointmentId])) {
        // Remove from waiting list if exists
        $waitingQuery = "UPDATE waiting_list SET status = 'cancelled' 
                        WHERE appointment_id = ? AND status = 'waiting'";
        $db->execute($waitingQuery, [$appointmentId]);
        
        logActivity($_SESSION['user_id'], 'update', 'appointments', $appointmentId, "تم إلغاء الموعد");
        
        echo json_encode([
            'success' => true,
            'message' => 'تم إلغاء الموعد بنجاح'
        ]);
    } else {
        throw new Exception('فشل في إلغاء الموعد');
    }
}
?>