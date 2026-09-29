<?php
// دالة للتحقق من تسجيل الدخول
// $requiredRole: دور واحد ('nurse') أو عدة أدوار (['nurse', 'doctor'])
function checkLogin($requiredRole = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../index.php');
        exit;
    }

    if ($requiredRole && !in_array($_SESSION['user_role'] ?? '', (array)$requiredRole, true)) {
        header('Location: ../index.php');
        exit;
    }
}

// دالة لتسجيل الأنشطة
function logActivity($userId, $action, $tableName = null, $recordId = null, $description = null) {
    $db = getDB();
    $query = "INSERT INTO activity_log (user_id, action, table_name, record_id, description, ip_address) 
              VALUES (?, ?, ?, ?, ?, ?)";
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return $db->execute($query, [$userId, $action, $tableName, $recordId, $description, $ipAddress]);
}

// توحيد تاريخ إكمال مرحلة العلاج إلى Y-m-d
// يقبل Y-m-d وكذلك الصيغة المحلية القديمة مثل "٤‏/١‏/٢٠٢٦" (يوم/شهر/سنة بأرقام عربية)
function normalizeStageDate($value) {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    // أرقام عربية وفارسية -> لاتينية، وحذف علامات الاتجاه
    $value = strtr($value, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
    $value = trim(preg_replace('/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\s]+/u', '', $value));

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
        [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
        [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }

    return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
}

// دالة لتنسيق التاريخ
function formatDate($date, $format = 'Y-m-d') {
    if (!$date) return '';
    
    $dateObj = new DateTime($date);
    
    switch ($format) {
        case 'arabic':
            $months = [
                1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
            ];
            
            $day = $dateObj->format('d');
            $month = $months[(int)$dateObj->format('m')];
            $year = $dateObj->format('Y');
            
            return "$day $month $year";
            
        case 'short':
            return $dateObj->format('d/m/Y');
            
        default:
            return $dateObj->format($format);
    }
}

// دالة لتنسيق الوقت
function formatTime($time) {
    if (!$time) return '';
    
    $timeObj = new DateTime($time);
    return $timeObj->format('H:i');
}

// دالة لحساب العمر
function calculateAge($birthDate) {
    if (!$birthDate) return '';
    
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    
    return $today->diff($birth)->y;
}

// دالة لتنظيف البيانات
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// دالة للتحقق من صحة رقم الهاتف السعودي
function validateSaudiPhone($phone) {
    $pattern = '/^(05|009665)[0-9]{8}$/';
    return preg_match($pattern, $phone);
}

// دالة للتحقق من صحة البريد الإلكتروني
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// دالة لإنشاء كلمة مرور آمنة
function generateSecurePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
}

// دالة لتشفير كلمة المرور
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// دالة للحصول على إحصائيات العيادة
function getClinicStats() {
    $db = getDB();
    
    $stats = [];
    
    // عدد المرضى اليوم
    $query = "SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE()";
    $result = $db->selectOne($query);
    $stats['today_patients'] = $result['count'] ?? 0;
    
    // المواعيد المنجزة اليوم
    $query = "SELECT COUNT(*) as count FROM appointments 
              WHERE appointment_date = CURDATE() AND status = 'completed'";
    $result = $db->selectOne($query);
    $stats['completed_appointments'] = $result['count'] ?? 0;
    
    // المرضى في الانتظار
    $query = "SELECT COUNT(*) as count FROM waiting_list WHERE status = 'waiting'";
    $result = $db->selectOne($query);
    $stats['waiting_patients'] = $result['count'] ?? 0;
    
    // إجمالي المرضى - بدون فلترة status
    $query = "SELECT COUNT(*) as count FROM patients";
    $result = $db->selectOne($query);
    $stats['total_patients'] = $result['count'] ?? 0;
    
    return $stats;
}

// دالة للحصول على الأنشطة الأخيرة
function getRecentActivities($limit = 5) {
    $db = getDB();
    
    $query = "SELECT al.*, u.full_name as user_name 
              FROM activity_log al 
              JOIN users u ON al.user_id = u.id 
              ORDER BY al.created_at DESC 
              LIMIT ?";
    
    return $db->select($query, [$limit]);
}

// دالة للحصول على المواعيد القادمة
function getUpcomingAppointments($limit = 5) {
    $db = getDB();
    
    $query = "SELECT a.*, p.name as patient_name 
              FROM appointments a 
              JOIN patients p ON a.patient_id = p.id 
              WHERE a.appointment_date >= CURDATE() 
              AND a.status IN ('scheduled', 'confirmed') 
              ORDER BY a.appointment_date, a.appointment_time 
              LIMIT ?";
    
    return $db->select($query, [$limit]);
}

// دالة للبحث عن المرضى - بدون فلترة status
function searchPatients($searchTerm, $limit = 50) {
    $db = getDB();
    
    $searchTerm = "%$searchTerm%";
    
    $query = "SELECT * FROM patients 
              WHERE (name LIKE ? OR phone LIKE ?) 
              ORDER BY name 
              LIMIT ?";
    
    return $db->select($query, [$searchTerm, $searchTerm, $limit]);
}

// دالة لإضافة مريض جديد - بدون حقل status
function addPatient($data) {
    $db = getDB();
    
    $query = "INSERT INTO patients (name, phone, age, gender, address, email, emergency_contact, 
              medical_history, allergies, blood_type, registration_date, created_by) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";
    
    $params = [
        $data['name'],
        $data['phone'],
        $data['age'],
        $data['gender'],
        $data['address'] ?? null,
        $data['email'] ?? null,
        $data['emergency_contact'] ?? null,
        $data['medical_history'] ?? null,
        $data['allergies'] ?? null,
        $data['blood_type'] ?? null,
        $_SESSION['user_id']
    ];
    
    if ($db->execute($query, $params)) {
        $patientId = $db->lastInsertId();
        logActivity($_SESSION['user_id'], 'create', 'patients', $patientId, "تم إضافة مريض جديد: {$data['name']}");
        return $patientId;
    }
    
    return false;
}

// دالة لإضافة موعد جديد
function addAppointment($data) {
    $db = getDB();
    
    $query = "INSERT INTO appointments (patient_id, appointment_date, appointment_time, 
              treatment_type, status, notes, estimated_duration, created_by) 
              VALUES (?, ?, ?, ?, 'scheduled', ?, ?, ?)";
    
    $params = [
        $data['patient_id'],
        $data['appointment_date'],
        $data['appointment_time'],
        $data['treatment_type'],
        $data['notes'] ?? null,
        $data['estimated_duration'] ?? 30,
        $_SESSION['user_id']
    ];
    
    if ($db->execute($query, $params)) {
        $appointmentId = $db->lastInsertId();
        
        // إضافة المريض لقائمة الانتظار إذا كان الموعد لليوم
        if ($data['appointment_date'] == date('Y-m-d')) {
            addToWaitingList($data['patient_id'], $appointmentId);
        }
        
        logActivity($_SESSION['user_id'], 'create', 'appointments', $appointmentId, "تم حجز موعد جديد");
        return $appointmentId;
    }
    
    return false;
}

// دالة لإضافة مريض لقائمة الانتظار
function addToWaitingList($patientId, $appointmentId = null, $priority = 'normal') {
    $db = getDB();
    
    // التحقق من وجود المريض في قائمة الانتظار
    $existingQuery = "SELECT id FROM waiting_list 
                      WHERE patient_id = ? AND status = 'waiting'";
    $existing = $db->selectOne($existingQuery, [$patientId]);
    
    if (!$existing) {
        $query = "INSERT INTO waiting_list (patient_id, appointment_id, priority, arrival_time) 
                  VALUES (?, ?, ?, NOW())";
        
        return $db->execute($query, [$patientId, $appointmentId, $priority]);
    }
    
    return false;
}

// دالة لإضافة علاج جديد
function addTreatment($data) {
    $db = getDB();
    
    $query = "INSERT INTO treatments (patient_id, appointment_id, treatment_date, treatment_type,
              symptoms, diagnosis, treatment_details, medications, cost, next_appointment_date, 
              notes, doctor_id) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    // Handle appointment_id properly - convert empty string to null
    $appointment_id = !empty($data['appointment_id']) ? $data['appointment_id'] : null;
    
    $params = [
        $data['patient_id'],
        $appointment_id,
        $data['treatment_date'],
        $data['treatment_type'],
        $data['symptoms'] ?? null,
        $data['diagnosis'],
        $data['treatment_details'],
        $data['medications'] ?? null,
        $data['cost'] ?? null,
        $data['next_appointment_date'] ?? null,
        $data['notes'] ?? null,
        $_SESSION['user_id']
    ];
    
    if ($db->execute($query, $params)) {
        $treatmentId = $db->lastInsertId();
        
        // تحديث تاريخ آخر زيارة للمريض
        $updatePatientQuery = "UPDATE patients SET last_visit_date = ? WHERE id = ?";
        $db->execute($updatePatientQuery, [$data['treatment_date'], $data['patient_id']]);
        
        // إذا كان هناك موعد مرتبط، تحديث حالته
        if ($data['appointment_id']) {
            $updateAppointmentQuery = "UPDATE appointments SET status = 'completed' WHERE id = ?";
            $db->execute($updateAppointmentQuery, [$data['appointment_id']]);
        }
        
        logActivity($_SESSION['user_id'], 'create', 'treatments', $treatmentId, "تم إضافة علاج جديد");
        return $treatmentId;
    }
    
    return false;
}

// دالة للحصول على تاريخ علاجات المريض
function getPatientTreatments($patientId) {
    $db = getDB();

    $query = "SELECT t.*, u.full_name as doctor_name,
                     COALESCE(SUM(pay.amount), 0) as total_paid
              FROM treatments t
              LEFT JOIN users u ON t.doctor_id = u.id
              LEFT JOIN payments pay ON t.id = pay.treatment_id
              WHERE t.patient_id = ?
              GROUP BY t.id
              ORDER BY t.treatment_date DESC";

    return $db->select($query, [$patientId]);
}

// دالة للحصول على معلومات المريض مع آخر علاج
function getPatientDetails($patientId) {
    $db = getDB();
    
    $query = "SELECT p.*, 
              (SELECT treatment_date FROM treatments 
               WHERE patient_id = p.id 
               ORDER BY treatment_date DESC LIMIT 1) as last_treatment_date
              FROM patients p 
              WHERE p.id = ?";
    
    return $db->selectOne($query, [$patientId]);
}

// دالة للحصول على مواعيد اليوم
function getTodayAppointments() {
    $db = getDB();
    
    $query = "SELECT a.*, p.name as patient_name, p.phone as patient_phone
              FROM appointments a 
              JOIN patients p ON a.patient_id = p.id 
              WHERE a.appointment_date = CURDATE() 
              ORDER BY a.appointment_time";
    
    return $db->select($query);
}

// دالة للحصول على قائمة الانتظار
function getWaitingList() {
    $db = getDB();
    
    $query = "SELECT w.*, p.name as patient_name, p.phone as patient_phone, p.age as patient_age, p.gender as patient_gender,
              a.treatment_type, a.appointment_time
              FROM waiting_list w
              JOIN patients p ON w.patient_id = p.id
              LEFT JOIN appointments a ON w.appointment_id = a.id
              WHERE w.status = 'waiting'
              ORDER BY CASE w.priority 
                         WHEN 'emergency' THEN 1 
                         WHEN 'urgent' THEN 2 
                         WHEN 'normal' THEN 3 
                         ELSE 4 
                       END, w.arrival_time";
    
    return $db->select($query);
}

// دالة لتحديث حالة المريض في قائمة الانتظار
function updateWaitingListStatus($waitingId, $status) {
    $db = getDB();
    
    $query = "UPDATE waiting_list SET status = ? WHERE id = ?";
    return $db->execute($query, [$status, $waitingId]);
}

// دالة لتحديث حالة الموعد
function updateAppointmentStatus($appointmentId, $status) {
    $db = getDB();
    
    $query = "UPDATE appointments SET status = ? WHERE id = ?";
    return $db->execute($query, [$status, $appointmentId]);
}

// دالة لحذف المريض من قائمة الانتظار
function removeFromWaitingList($patientId) {
    $db = getDB();
    
    $query = "UPDATE waiting_list SET status = 'completed' 
              WHERE patient_id = ? AND status = 'waiting'";
    return $db->execute($query, [$patientId]);
}

// دالة للتحقق من تضارب المواعيد
function checkAppointmentConflict($date, $time, $duration = 30, $excludeId = null) {
    $db = getDB();
    
    $endTime = date('H:i:s', strtotime($time) + ($duration * 60));
    
    $query = "SELECT COUNT(*) as count FROM appointments 
              WHERE appointment_date = ? 
              AND status IN ('scheduled', 'confirmed')
              AND (
                  (appointment_time <= ? AND DATE_ADD(STR_TO_DATE(appointment_time, '%H:%i:%s'), INTERVAL estimated_duration MINUTE) > ?)
                  OR 
                  (appointment_time < ? AND appointment_time >= ?)
              )";
    
    $params = [$date, $time, $time, $endTime, $time];
    
    if ($excludeId) {
        $query .= " AND id != ?";
        $params[] = $excludeId;
    }
    
    $result = $db->selectOne($query, $params);
    return $result['count'] > 0;
}

// دالة للحصول على المرضى مع البحث والترقيم - بدون فلترة status
function getPatientsList($search = '', $page = 1, $perPage = 20) {
    $db = getDB();
    
    $offset = ($page - 1) * $perPage;
    $searchTerm = "%$search%";
    
    $whereClause = "";
    $params = [];
    
    if ($search) {
        $whereClause = "WHERE (p.name LIKE ? OR p.phone LIKE ?)";
        $params = [$searchTerm, $searchTerm];
    }
    
    $query = "SELECT p.*, 
              (SELECT COUNT(*) FROM treatments t WHERE t.patient_id = p.id) as treatment_count
              FROM patients p 
              $whereClause 
              ORDER BY p.name 
              LIMIT ? OFFSET ?";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $patients = $db->select($query, $params);
    
    // الحصول على العدد الكلي للمرضى
    $countQuery = "SELECT COUNT(*) as total FROM patients p $whereClause";
    $countParams = $search ? [$searchTerm, $searchTerm] : [];
    $totalResult = $db->selectOne($countQuery, $countParams);
    $total = $totalResult['total'];
    
    return [
        'patients' => $patients,
        'total' => $total,
        'pages' => ceil($total / $perPage),
        'current_page' => $page
    ];
}

// دالة لتحديث حالة الدفع للعلاج
function updateTreatmentPaymentStatus($treatmentId) {
    $db = getDB();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->prepare("
        SELECT t.cost,
               COALESCE(SUM(pay.amount), 0) as total_paid
        FROM treatments t
        LEFT JOIN payments pay ON t.id = pay.treatment_id
        WHERE t.id = ?
        GROUP BY t.id, t.cost
    ");
    $stmt->execute([$treatmentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $status = 'unpaid';
        if ($result['total_paid'] >= $result['cost']) {
            $status = 'paid';
        } elseif ($result['total_paid'] > 0) {
            $status = 'partial';
        }
        
        $update_stmt = $pdo->prepare("UPDATE treatments SET payment_status = ? WHERE id = ?");
        $update_stmt->execute([$status, $treatmentId]);
    }
}

// دالة لإضافة دفعة جديدة
function addPayment($data) {
    $db = getDB();
    $pdo = $db->getConnection();

    $query = "INSERT INTO payments (patient_id, treatment_id, amount, payment_method,
              receipt_number, notes, created_by)
              VALUES (?, ?, ?, ?, ?, ?, ?)";

    // تحويل treatment_id الفارغ إلى null
    $treatment_id = !empty($data['treatment_id']) ? $data['treatment_id'] : null;

    $params = [
        $data['patient_id'],
        $treatment_id,
        $data['amount'],
        $data['payment_method'],
        $data['receipt_number'] ?? '',
        $data['notes'] ?? '',
        $_SESSION['user_id']
    ];

    $stmt = $pdo->prepare($query);
    if ($stmt->execute($params)) {
        $paymentId = $pdo->lastInsertId();

        // تحديث حالة الدفع للعلاج إذا كان مرتبط بعلاج محدد
        if (!empty($treatment_id)) {
            updateTreatmentPaymentStatus($treatment_id);
        }

        logActivity($_SESSION['user_id'], 'create', 'payments', $paymentId,
                   "تم إضافة دفعة بمبلغ {$data['amount']} ليرة سورية");
        return $paymentId;
    }

    return false;
}

// دالة للحصول على رصيد المريض
function getPatientBalance($patientId) {
    $db = getDB();
    
    $query = "SELECT 
                COALESCE(SUM(t.cost), 0) as total_cost,
                COALESCE(SUM(pay.amount), 0) as total_paid,
                (COALESCE(SUM(t.cost), 0) - COALESCE(SUM(pay.amount), 0)) as remaining_balance
              FROM patients p
              LEFT JOIN treatments t ON p.id = t.patient_id AND t.cost IS NOT NULL
              LEFT JOIN payments pay ON p.id = pay.patient_id
              WHERE p.id = ?
              GROUP BY p.id";
    
    return $db->selectOne($query, [$patientId]);
}

// دالة لتنسيق العملة السورية
function formatSyrianCurrency($amount) {
    // تنسيق الليرة السورية بدون خانات عشرية
    return number_format($amount, 0);
}

// دالة لتنسيق العملة السورية مع النص
function formatSyrianCurrencyWithText($amount) {
    return number_format($amount, 0) . ' ليرة سورية';
}
  
?>