<?php
// api/patient_info.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مسموح']);
    exit;
}

$db = getDB();
$pdo = $db->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$patient_id = $_GET['id'] ?? $_POST['patient_id'] ?? 0;

try {
    switch ($action) {
        case 'get_patient_info':
        case '':
            if (!$patient_id) {
                throw new Exception('معرف المريض مطلوب');
            }
            
            // جلب معلومات المريض
            $stmt = $pdo->prepare("
                SELECT p.*, 
                       COUNT(t.id) as total_treatments,
                       MAX(t.treatment_date) as last_treatment_date,
                       COALESCE(SUM(t.cost), 0) as total_cost,
                       COALESCE(SUM(pay.amount), 0) as total_paid
                FROM patients p
                LEFT JOIN treatments t ON p.id = t.patient_id
                LEFT JOIN payments pay ON p.id = pay.patient_id
                WHERE p.id = ? AND p.status = 'active'
                GROUP BY p.id
            ");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient) {
                throw new Exception('المريض غير موجود');
            }
            
            // إضافة الرصيد المتبقي
            $patient['remaining_balance'] = $patient['total_cost'] - $patient['total_paid'];
            
            echo json_encode([
                'success' => true,
                'patient' => $patient
            ]);
            break;
            
        case 'get_appointments':
            if (!$patient_id) {
                throw new Exception('معرف المريض مطلوب');
            }
            
            $status = $_GET['status'] ?? '';
            $where_status = $status ? "AND status = ?" : "";
            $params = $status ? [$patient_id, $status] : [$patient_id];
            
            $stmt = $pdo->prepare("
                SELECT * FROM appointments 
                WHERE patient_id = ? $where_status
                ORDER BY appointment_date DESC, appointment_time DESC
            ");
            $stmt->execute($params);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'appointments' => $appointments
            ]);
            break;
            
        case 'get_dental_history':
            if (!$patient_id) {
                throw new Exception('معرف المريض مطلوب');
            }
            
            $stmt = $pdo->prepare("
                SELECT t.*, u.full_name as doctor_name
                FROM treatments t
                LEFT JOIN users u ON t.doctor_id = u.id
                WHERE t.patient_id = ?
                ORDER BY t.treatment_date DESC
            ");
            $stmt->execute([$patient_id]);
            $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'treatments' => $treatments
            ]);
            break;
            
        case 'get_tooth_status':
            if (!$patient_id) {
                throw new Exception('معرف المريض مطلوب');
            }
            
            $stmt = $pdo->prepare("
                SELECT ts.*, u.full_name as doctor_name
                FROM tooth_status ts
                LEFT JOIN users u ON ts.doctor_id = u.id
                WHERE ts.patient_id = ?
            ");
            $stmt->execute([$patient_id]);
            $teeth_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // تنظيم البيانات حسب رقم السن
            $organized_data = [];
            foreach ($teeth_status as $tooth) {
                $organized_data[$tooth['tooth_number']] = $tooth;
            }
            
            echo json_encode([
                'success' => true,
                'teeth_status' => $organized_data
            ]);
            break;
            
        case 'update_tooth_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('طريقة الطلب غير صحيحة');
            }
            
            $tooth_number = $_POST['tooth_number'] ?? '';
            $status = $_POST['status'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if (!$patient_id || !$tooth_number || !$status) {
                throw new Exception('جميع الحقول مطلوبة');
            }
            
            // التحقق من صحة رقم السن
            if (!preg_match('/^[1-4][1-8]$/', $tooth_number)) {
                throw new Exception('رقم السن غير صحيح');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tooth_status (patient_id, tooth_number, status, notes, doctor_id, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), notes = VALUES(notes), 
                doctor_id = VALUES(doctor_id), updated_at = VALUES(updated_at)
            ");
            
            $result = $stmt->execute([$patient_id, $tooth_number, $status, $notes, $_SESSION['user_id']]);
            
            if (!$result) {
                throw new Exception('فشل في تحديث حالة السن');
            }
            
            // تسجيل النشاط
            logActivity($_SESSION['user_id'], 'update', 'tooth_status', $patient_id, 
                       "تم تحديث حالة السن رقم $tooth_number إلى $status");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث حالة السن بنجاح'
            ]);
            break;
            
        case 'add_note':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('طريقة الطلب غير صحيحة');
            }
            
            $note_text = $_POST['note_text'] ?? '';
            $note_type = $_POST['note_type'] ?? 'general';
            
            if (!$patient_id || !$note_text) {
                throw new Exception('نص الملاحظة مطلوب');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO patient_notes (patient_id, note_text, note_type, doctor_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([$patient_id, $note_text, $note_type, $_SESSION['user_id']]);
            
            if (!$result) {
                throw new Exception('فشل في إضافة الملاحظة');
            }
            
            $note_id = $pdo->lastInsertId();
            
            // تسجيل النشاط
            logActivity($_SESSION['user_id'], 'create', 'patient_notes', $note_id, 
                       "تم إضافة ملاحظة جديدة للمريض");
            
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة الملاحظة بنجاح',
                'note_id' => $note_id
            ]);
            break;
            
        case 'get_treatment_templates':
            $treatment_type = $_GET['treatment_type'] ?? '';
            $where_type = $treatment_type ? "WHERE treatment_type = ? OR is_public = TRUE" : "WHERE is_public = TRUE";
            $params = $treatment_type ? [$treatment_type] : [];
            
            $stmt = $pdo->prepare("
                SELECT * FROM treatment_templates 
                $where_type
                ORDER BY template_name
            ");
            $stmt->execute($params);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // فك تشفير JSON للخطوات
            foreach ($templates as &$template) {
                if ($template['treatment_steps']) {
                    $template['treatment_steps'] = json_decode($template['treatment_steps'], true);
                }
            }
            
            echo json_encode([
                'success' => true,
                'templates' => $templates
            ]);
            break;
            
        case 'search_patients':
            $search = $_GET['search'] ?? '';
            $limit = min(50, intval($_GET['limit'] ?? 20));
            
            if (strlen($search) < 2) {
                echo json_encode(['success' => true, 'patients' => []]);
                break;
            }
            
            $stmt = $pdo->prepare("
                SELECT id, name, phone, age, gender
                FROM patients 
                WHERE status = 'active' AND (name LIKE ? OR phone LIKE ?)
                ORDER BY name
                LIMIT ?
            ");
            $searchTerm = "%$search%";
            $stmt->execute([$searchTerm, $searchTerm, $limit]);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'patients' => $patients
            ]);
            break;
            
        case 'get_treatment_stages':
            $treatment_type = $_GET['treatment_type'] ?? '';
            
            // قائمة مراحل المعالجة حسب النوع
            $stages_map = [
                'حشو_الأسنان' => [
                    'تخدير_موضعي' => 'التخدير الموضعي',
                    'إزالة_التسوس' => 'إزالة التسوس',
                    'تحضير_السن' => 'تحضير السن',
                    'وضع_الحشو' => 'وضع الحشو',
                    'تشكيل_نهائي' => 'التشكيل النهائي'
                ],
                'علاج_الجذور' => [
                    'تخدير' => 'التخدير',
                    'فتح_السن' => 'فتح السن',
                    'تنظيف_القنوات' => 'تنظيف القنوات',
                    'حشو_القنوات' => 'حشو القنوات',
                    'إغلاق_مؤقت' => 'الإغلاق المؤقت',
                    'تركيب_تاج' => 'تركيب التاج (اختياري)'
                ],
                'خلع_الأسنان' => [
                    'تخدير' => 'التخدير',
                    'خلع_السن' => 'خلع السن',
                    'تنظيف_المكان' => 'تنظيف مكان الخلع',
                    'خياطة' => 'الخياطة (إن لزم)'
                ],
                'تنظيف_الأسنان' => [
                    'فحص_أولي' => 'الفحص الأولي',
                    'إزالة_الجير' => 'إزالة الجير',
                    'تنظيف_بالفرشاة' => 'التنظيف بالفرشاة',
                    'تلميع' => 'التلميع',
                    'فلورايد' => 'تطبيق الفلورايد'
                ],
                'تركيب_تاج' => [
                    'تحضير_السن' => 'تحضير السن',
                    'أخذ_طبعة' => 'أخذ الطبعة',
                    'تركيب_مؤقت' => 'التركيب المؤقت',
                    'تجربة_التاج' => 'تجربة التاج',
                    'تثبيت_نهائي' => 'التثبيت النهائي'
                ],
                'زراعة_الأسنان' => [
                    'تخطيط' => 'التخطيط والفحص',
                    'تحضير_موقع' => 'تحضير موقع الزراعة',
                    'زراعة_البرغي' => 'زراعة البرغي',
                    'فترة_شفاء' => 'فترة الشفاء (3-6 أشهر)',
                    'تركيب_التاج' => 'تركيب التاج النهائي'
                ]
            ];
            
            $stages = $stages_map[$treatment_type] ?? [];
            
            echo json_encode([
                'success' => true,
                'stages' => $stages
            ]);
            break;
            
        case 'get_patient_statistics':
            if (!$patient_id) {
                throw new Exception('معرف المريض مطلوب');
            }
            
            // إحصائيات شاملة للمريض
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(t.id) as total_treatments,
                    COUNT(CASE WHEN t.treatment_status = 'completed' THEN 1 END) as completed_treatments,
                    COUNT(CASE WHEN t.treatment_status = 'in_progress' THEN 1 END) as ongoing_treatments,
                    COALESCE(SUM(t.cost), 0) as total_cost,
                    COALESCE(AVG(t.cost), 0) as avg_treatment_cost,
                    MAX(t.treatment_date) as last_treatment_date,
                    MIN(t.treatment_date) as first_treatment_date,
                    COUNT(DISTINCT t.doctor_id) as different_doctors,
                    COUNT(DISTINCT DATE_FORMAT(t.treatment_date, '%Y-%m')) as treatment_months
                FROM treatments t
                WHERE t.patient_id = ?
            ");
            $stmt->execute([$patient_id]);
            $treatment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // إحصائيات المدفوعات
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(p.id) as total_payments,
                    COALESCE(SUM(p.amount), 0) as total_paid,
                    COALESCE(AVG(p.amount), 0) as avg_payment,
                    MAX(p.payment_date) as last_payment_date
                FROM payments p
                WHERE p.patient_id = ?
            ");
            $stmt->execute([$patient_id]);
            $payment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // إحصائيات الأسنان
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(ts.tooth_number) as recorded_teeth,
                    COUNT(CASE WHEN ts.status = 'healthy' THEN 1 END) as healthy_teeth,
                    COUNT(CASE WHEN ts.status = 'treated' THEN 1 END) as treated_teeth,
                    COUNT(CASE WHEN ts.status = 'problem' THEN 1 END) as problem_teeth,
                    COUNT(CASE WHEN ts.status = 'missing' THEN 1 END) as missing_teeth,
                    COUNT(CASE WHEN ts.status = 'crown' THEN 1 END) as crown_teeth
                FROM tooth_status ts
                WHERE ts.patient_id = ?
            ");
            $stmt->execute([$patient_id]);
            $dental_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // حساب الرصيد
            $remaining_balance = $treatment_stats['total_cost'] - $payment_stats['total_paid'];
            
            echo json_encode([
                'success' => true,
                'statistics' => [
                    'treatments' => $treatment_stats,
                    'payments' => $payment_stats,
                    'dental' => $dental_stats,
                    'balance' => [
                        'total_cost' => $treatment_stats['total_cost'],
                        'total_paid' => $payment_stats['total_paid'],
                        'remaining_balance' => $remaining_balance
                    ]
                ]
            ]);
            break;
            
        default:
            throw new Exception('العملية غير مدعومة');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    error_log("Database error in patient API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في قاعدة البيانات'
    ]);
}
?>