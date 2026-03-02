<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$doctor_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'get_all':
            $stmt = $pdo->query("
                SELECT * FROM treatment_types 
                ORDER BY display_order ASC, name_ar ASC
            ");
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'get_one':
            $code = $_GET['code'] ?? '';
            if (empty($code)) {
                throw new Exception('كود نوع العلاج مطلوب');
            }
            
            $stmt = $pdo->prepare("SELECT * FROM treatment_types WHERE code = ?");
            $stmt->execute([$code]);
            $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$response['data']) {
                throw new Exception('نوع العلاج غير موجود');
            }
            
            $response['success'] = true;
            break;
            
        case 'add':
            $code = trim($_POST['code'] ?? '');
            $name_ar = trim($_POST['name_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $icon_class = trim($_POST['icon_class'] ?? '');
            $icon_color = trim($_POST['icon_color'] ?? '');
            $display_order = (int)($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // التحقق من صحة البيانات
            if (empty($code) || empty($name_ar)) {
                throw new Exception('كود نوع العلاج والاسم مطلوبان');
            }
            
            // التحقق من عدم تكرار الكود
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('كود نوع العلاج موجود مسبقاً');
            }
            
            // إضافة نوع العلاج الجديد
            $stmt = $pdo->prepare("
                INSERT INTO treatment_types (code, name_ar, description_ar, icon_class, icon_color, display_order, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $code, $name_ar, $description_ar, $icon_class, 
                $icon_color, $display_order, $is_active
            ]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم إضافة نوع العلاج بنجاح';
            } else {
                throw new Exception('فشل في إضافة نوع العلاج');
            }
            break;
            
        case 'edit':
            $original_code = trim($_POST['original_code'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $name_ar = trim($_POST['name_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $icon_class = trim($_POST['icon_class'] ?? '');
            $icon_color = trim($_POST['icon_color'] ?? '');
            $display_order = (int)($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($original_code) || empty($code) || empty($name_ar)) {
                throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
            }
            
            // التحقق من وجود نوع العلاج
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$original_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('نوع العلاج غير موجود');
            }
            
            // التحقق من عدم تكرار الكود الجديد (إذا تم تغييره)
            if ($original_code !== $code) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('كود نوع العلاج الجديد موجود مسبقاً');
                }
            }
            
            $pdo->beginTransaction();
            
            try {
                // تحديث نوع العلاج
                $stmt = $pdo->prepare("
                    UPDATE treatment_types 
                    SET code = ?, name_ar = ?, description_ar = ?, icon_class = ?, 
                        icon_color = ?, display_order = ?, is_active = ?, updated_at = NOW()
                    WHERE code = ?
                ");
                
                $result = $stmt->execute([
                    $code, $name_ar, $description_ar, $icon_class, 
                    $icon_color, $display_order, $is_active, $original_code
                ]);
                
                if (!$result) {
                    throw new Exception('فشل في تحديث نوع العلاج');
                }
                
                // إذا تم تغيير الكود، نحتاج لتحديث الجداول المرتبطة
                if ($original_code !== $code) {
                    // تحديث treatment_options
                    $stmt = $pdo->prepare("
                        UPDATE treatment_options 
                        SET treatment_type_code = ? 
                        WHERE treatment_type_code = ?
                    ");
                    $stmt->execute([$code, $original_code]);
                    
                    // تحديث treatment_stages
                    $stmt = $pdo->prepare("
                        UPDATE treatment_stages 
                        SET treatment_type_code = ? 
                        WHERE treatment_type_code = ?
                    ");
                    $stmt->execute([$code, $original_code]);
                    
                    // تحديث treatments
                    $stmt = $pdo->prepare("
                        UPDATE treatments 
                        SET treatment_type = ? 
                        WHERE treatment_type = ?
                    ");
                    $stmt->execute([$code, $original_code]);
                }
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث نوع العلاج بنجاح';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'delete':
            $code = $_POST['code'] ?? '';
            if (empty($code)) {
                throw new Exception('كود نوع العلاج مطلوب');
            }
            
            // التحقق من وجود نوع العلاج
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('نوع العلاج غير موجود');
            }
            
            // التحقق من عدم استخدام نوع العلاج في العلاجات
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatments WHERE treatment_type = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('لا يمكن حذف نوع العلاج لأنه مستخدم في علاجات موجودة');
            }
            
            $pdo->beginTransaction();
            
            try {
                // حذف خيارات العلاج المرتبطة
                $stmt = $pdo->prepare("DELETE FROM treatment_options WHERE treatment_type_code = ?");
                $stmt->execute([$code]);
                
                // حذف مراحل العلاج المرتبطة
                $stmt = $pdo->prepare("DELETE FROM treatment_stages WHERE treatment_type_code = ?");
                $stmt->execute([$code]);
                
                // حذف نوع العلاج
                $stmt = $pdo->prepare("DELETE FROM treatment_types WHERE code = ?");
                $result = $stmt->execute([$code]);
                
                if (!$result) {
                    throw new Exception('فشل في حذف نوع العلاج');
                }
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم حذف نوع العلاج وجميع البيانات المرتبطة به بنجاح';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'update_order':
            $orders = json_decode($_POST['orders'] ?? '[]', true);
            if (empty($orders)) {
                throw new Exception('ترتيب العناصر مطلوب');
            }
            
            $pdo->beginTransaction();
            
            try {
                foreach ($orders as $order_data) {
                    $stmt = $pdo->prepare("
                        UPDATE treatment_types 
                        SET display_order = ? 
                        WHERE code = ?
                    ");
                    $stmt->execute([$order_data['order'], $order_data['code']]);
                }
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث ترتيب أنواع العلاج بنجاح';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'get_icon_options':
            // قائمة بالأيقونات المتاحة لأنواع العلاج
            $icons = [
                ['class' => 'fas fa-tooth', 'name' => 'سن عادي', 'color' => 'text-blue-600'],
                ['class' => 'fas fa-band-aid', 'name' => 'علاج عام', 'color' => 'text-green-600'],
                ['class' => 'fas fa-syringe', 'name' => 'حقن', 'color' => 'text-red-600'],
                ['class' => 'fas fa-cut', 'name' => 'جراحة', 'color' => 'text-purple-600'],
                ['class' => 'fas fa-crown', 'name' => 'تاج', 'color' => 'text-yellow-600'],
                ['class' => 'fas fa-hammer', 'name' => 'حشوة', 'color' => 'text-gray-600'],
                ['class' => 'fas fa-magic', 'name' => 'تجميل', 'color' => 'text-pink-600'],
                ['class' => 'fas fa-microscope', 'name' => 'تشخيص', 'color' => 'text-indigo-600'],
                ['class' => 'fas fa-shield-alt', 'name' => 'وقائي', 'color' => 'text-teal-600'],
                ['class' => 'fas fa-fire', 'name' => 'علاج عصب', 'color' => 'text-orange-600'],
                ['class' => 'fas fa-cog', 'name' => 'تقويم', 'color' => 'text-cyan-600'],
                ['class' => 'fas fa-sparkles', 'name' => 'تبييض', 'color' => 'text-yellow-400']
            ];
            
            $response['data'] = $icons;
            $response['success'] = true;
            break;
            
        default:
            throw new Exception('عملية غير مدعومة');
    }
    
} catch (PDOException $e) {
    $response['message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// إرجاع الاستجابة بتنسيق JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>