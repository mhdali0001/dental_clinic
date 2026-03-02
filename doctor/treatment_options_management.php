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

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'get_all':
            // Check if tables exist first
            $stmt = $pdo->query("SHOW TABLES LIKE 'treatment_options'");
            if ($stmt->rowCount() == 0) {
                throw new Exception('جدول treatment_options غير موجود');
            }

            // Get available columns
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Check if price column exists
            $hasPriceColumn = in_array('price', $columns);

            // Build safe query
            $orderBy = "ORDER BY treatment_type_code ASC";
            if (in_array('display_order', $columns)) {
                $orderBy .= ", display_order ASC";
            }
            if (in_array('name_ar', $columns)) {
                $orderBy .= ", name_ar ASC";
            }

            // Try with JOIN first
            $stmt = $pdo->query("SHOW TABLES LIKE 'treatment_types'");
            $hasTypesTable = ($stmt->rowCount() > 0);

            if ($hasTypesTable) {
                try {
                    if ($hasPriceColumn) {
                        $stmt = $pdo->query("
                            SELECT topt.*, ttype.name_ar as treatment_type_name
                            FROM treatment_options topt
                            LEFT JOIN treatment_types ttype ON topt.treatment_type_code = ttype.code
                            $orderBy
                        ");
                    } else {
                        $stmt = $pdo->query("
                            SELECT topt.*, ttype.name_ar as treatment_type_name, 0.00 as price
                            FROM treatment_options topt
                            LEFT JOIN treatment_types ttype ON topt.treatment_type_code = ttype.code
                            $orderBy
                        ");
                    }
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                } catch (PDOException $e) {
                    // Fallback to simple query
                    if ($hasPriceColumn) {
                        $stmt = $pdo->query("SELECT * FROM treatment_options $orderBy");
                    } else {
                        $stmt = $pdo->query("SELECT *, 0.00 as price FROM treatment_options $orderBy");
                    }
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                    $response['message'] = 'تم جلب البيانات بدون ربط (JOIN خطأ: ' . $e->getMessage() . ')';
                }
            } else {
                // No types table, simple query
                if ($hasPriceColumn) {
                    $stmt = $pdo->query("SELECT * FROM treatment_options $orderBy");
                } else {
                    $stmt = $pdo->query("SELECT *, 0.00 as price FROM treatment_options $orderBy");
                }
                $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                $response['message'] = 'تم جلب البيانات - جدول treatment_types غير موجود';
            }

            // Ensure all records have price field
            if ($response['data']) {
                foreach ($response['data'] as &$record) {
                    if (!isset($record['price'])) {
                        $record['price'] = '0.00';
                    }
                }
            }

            $response['has_price_column'] = $hasPriceColumn;
            break;
            
        case 'get_one':
            $treatment_type_code = $_GET['treatment_type_code'] ?? '';
            $option_code = $_GET['option_code'] ?? '';

            if (empty($treatment_type_code) || empty($option_code)) {
                throw new Exception('كود نوع العلاج وكود الخيار مطلوبان');
            }

            // Check if price column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
            $hasPriceColumn = ($stmt->rowCount() > 0);

            if ($hasPriceColumn) {
                $stmt = $pdo->prepare("
                    SELECT topt.*, ttype.name_ar as treatment_type_name
                    FROM treatment_options topt
                    LEFT JOIN treatment_types ttype ON topt.treatment_type_code = ttype.code
                    WHERE topt.treatment_type_code = ? AND topt.option_code = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT topt.*, ttype.name_ar as treatment_type_name, 0.00 as price
                    FROM treatment_options topt
                    LEFT JOIN treatment_types ttype ON topt.treatment_type_code = ttype.code
                    WHERE topt.treatment_type_code = ? AND topt.option_code = ?
                ");
            }

            $stmt->execute([$treatment_type_code, $option_code]);
            $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$response['data']) {
                throw new Exception('خيار العلاج غير موجود');
            }

            // Ensure price field exists
            if (!isset($response['data']['price'])) {
                $response['data']['price'] = '0.00';
            }

            $response['success'] = true;
            $response['has_price_column'] = $hasPriceColumn;
            break;
            
        case 'get_by_treatment_type':
            $treatment_type_code = $_GET['treatment_type_code'] ?? '';
            if (empty($treatment_type_code)) {
                throw new Exception('كود نوع العلاج مطلوب');
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM treatment_options 
                WHERE treatment_type_code = ? 
                ORDER BY display_order ASC, name_ar ASC
            ");
            $stmt->execute([$treatment_type_code]);
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'add':
            $treatment_type_code = trim($_POST['treatment_type_code'] ?? '');
            $option_code = trim($_POST['option_code'] ?? '');
            $name_ar = trim($_POST['name_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            $display_order = (int)($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // التحقق من صحة البيانات
            if (empty($treatment_type_code) || empty($option_code) || empty($name_ar)) {
                throw new Exception('كود نوع العلاج وكود الخيار والاسم مطلوبون');
            }
            
            // التحقق من وجود نوع العلاج
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$treatment_type_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('نوع العلاج غير موجود');
            }
            
            // التحقق من عدم تكرار كود الخيار لنفس نوع العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_options 
                WHERE treatment_type_code = ? AND option_code = ?
            ");
            $stmt->execute([$treatment_type_code, $option_code]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('كود خيار العلاج موجود مسبقاً لهذا النوع');
            }
            
            // إضافة خيار العلاج الجديد
            $stmt = $pdo->prepare("
                INSERT INTO treatment_options
                (treatment_type_code, option_code, name_ar, description_ar, price, display_order, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $result = $stmt->execute([
                $treatment_type_code, $option_code, $name_ar, $description_ar, $price, $display_order, $is_active
            ]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم إضافة خيار العلاج بنجاح';
            } else {
                throw new Exception('فشل في إضافة خيار العلاج');
            }
            break;
            
        case 'edit':
            $original_treatment_type_code = trim($_POST['original_treatment_type_code'] ?? '');
            $original_option_code = trim($_POST['original_option_code'] ?? '');
            $treatment_type_code = trim($_POST['treatment_type_code'] ?? '');
            $option_code = trim($_POST['option_code'] ?? '');
            $name_ar = trim($_POST['name_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            $display_order = (int)($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($original_treatment_type_code) || empty($original_option_code) || 
                empty($treatment_type_code) || empty($option_code) || empty($name_ar)) {
                throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
            }
            
            // التحقق من وجود خيار العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_options 
                WHERE treatment_type_code = ? AND option_code = ?
            ");
            $stmt->execute([$original_treatment_type_code, $original_option_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('خيار العلاج غير موجود');
            }
            
            // التحقق من وجود نوع العلاج الجديد
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$treatment_type_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('نوع العلاج الجديد غير موجود');
            }
            
            // التحقق من عدم تكرار الكود الجديد (إذا تم تغييره)
            if ($original_treatment_type_code !== $treatment_type_code || $original_option_code !== $option_code) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM treatment_options 
                    WHERE treatment_type_code = ? AND option_code = ?
                ");
                $stmt->execute([$treatment_type_code, $option_code]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('كود خيار العلاج الجديد موجود مسبقاً');
                }
            }
            
            // تحديث خيار العلاج
            $stmt = $pdo->prepare("
                UPDATE treatment_options
                SET treatment_type_code = ?, option_code = ?, name_ar = ?, description_ar = ?,
                    price = ?, display_order = ?, is_active = ?, updated_at = NOW()
                WHERE treatment_type_code = ? AND option_code = ?
            ");

            $result = $stmt->execute([
                $treatment_type_code, $option_code, $name_ar, $description_ar, $price,
                $display_order, $is_active, $original_treatment_type_code, $original_option_code
            ]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم تحديث خيار العلاج بنجاح';
            } else {
                throw new Exception('فشل في تحديث خيار العلاج');
            }
            break;
            
        case 'delete':
            $treatment_type_code = $_POST['treatment_type_code'] ?? '';
            $option_code = $_POST['option_code'] ?? '';
            
            if (empty($treatment_type_code) || empty($option_code)) {
                throw new Exception('كود نوع العلاج وكود الخيار مطلوبان');
            }
            
            // التحقق من وجود خيار العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_options 
                WHERE treatment_type_code = ? AND option_code = ?
            ");
            $stmt->execute([$treatment_type_code, $option_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('خيار العلاج غير موجود');
            }
            
            // حذف خيار العلاج
            $stmt = $pdo->prepare("
                DELETE FROM treatment_options 
                WHERE treatment_type_code = ? AND option_code = ?
            ");
            $result = $stmt->execute([$treatment_type_code, $option_code]);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم حذف خيار العلاج بنجاح';
            } else {
                throw new Exception('فشل في حذف خيار العلاج');
            }
            break;
            
        case 'update_order':
            $treatment_type_code = $_POST['treatment_type_code'] ?? '';
            $orders = json_decode($_POST['orders'] ?? '[]', true);
            
            if (empty($treatment_type_code) || empty($orders)) {
                throw new Exception('كود نوع العلاج وترتيب العناصر مطلوبان');
            }
            
            $pdo->beginTransaction();
            
            try {
                foreach ($orders as $order_data) {
                    $stmt = $pdo->prepare("
                        UPDATE treatment_options 
                        SET display_order = ? 
                        WHERE treatment_type_code = ? AND option_code = ?
                    ");
                    $stmt->execute([$order_data['order'], $treatment_type_code, $order_data['option_code']]);
                }
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث ترتيب خيارات العلاج بنجاح';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'get_treatment_types':
            // جلب جميع أنواع العلاج النشطة للاستخدام في القوائم المنسدلة
            $stmt = $pdo->query("
                SELECT code, name_ar 
                FROM treatment_types 
                WHERE is_active = 1 
                ORDER BY display_order ASC, name_ar ASC
            ");
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'bulk_update_status':
            $treatment_type_code = $_POST['treatment_type_code'] ?? '';
            $option_codes = json_decode($_POST['option_codes'] ?? '[]', true);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($treatment_type_code) || empty($option_codes)) {
                throw new Exception('كود نوع العلاج وأكواد الخيارات مطلوبة');
            }
            
            $pdo->beginTransaction();
            
            try {
                $placeholders = str_repeat('?,', count($option_codes) - 1) . '?';
                $stmt = $pdo->prepare("
                    UPDATE treatment_options 
                    SET is_active = ?, updated_at = NOW() 
                    WHERE treatment_type_code = ? AND option_code IN ($placeholders)
                ");
                
                $params = array_merge([$is_active, $treatment_type_code], $option_codes);
                $stmt->execute($params);
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث حالة خيارات العلاج بنجاح';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'duplicate':
            $source_treatment_type_code = $_POST['source_treatment_type_code'] ?? '';
            $target_treatment_type_code = $_POST['target_treatment_type_code'] ?? '';
            
            if (empty($source_treatment_type_code) || empty($target_treatment_type_code)) {
                throw new Exception('كود نوع العلاج المصدر والهدف مطلوبان');
            }
            
            // التحقق من وجود نوعي العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_types 
                WHERE code IN (?, ?) AND is_active = 1
            ");
            $stmt->execute([$source_treatment_type_code, $target_treatment_type_code]);
            if ($stmt->fetchColumn() < 2) {
                throw new Exception('أحد أنواع العلاج غير موجود أو غير نشط');
            }
            
            $pdo->beginTransaction();
            
            try {
                // جلب خيارات العلاج من المصدر
                $stmt = $pdo->prepare("
                    SELECT option_code, name_ar, description_ar, price, display_order, is_active
                    FROM treatment_options
                    WHERE treatment_type_code = ?
                ");
                $stmt->execute([$source_treatment_type_code]);
                $source_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($source_options)) {
                    throw new Exception('لا توجد خيارات في نوع العلاج المصدر');
                }
                
                // نسخ الخيارات إلى النوع الهدف
                $inserted_count = 0;
                foreach ($source_options as $option) {
                    // التحقق من عدم وجود الخيار في النوع الهدف
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM treatment_options 
                        WHERE treatment_type_code = ? AND option_code = ?
                    ");
                    $stmt->execute([$target_treatment_type_code, $option['option_code']]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        // إدراج الخيار الجديد
                        $stmt = $pdo->prepare("
                            INSERT INTO treatment_options
                            (treatment_type_code, option_code, name_ar, description_ar, price, display_order, is_active, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $target_treatment_type_code,
                            $option['option_code'],
                            $option['name_ar'],
                            $option['description_ar'],
                            $option['price'],
                            $option['display_order'],
                            $option['is_active']
                        ]);
                        $inserted_count++;
                    }
                }
                
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = "تم نسخ $inserted_count خيار علاج بنجاح";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'bulk_update_price':
            $treatment_type_code = $_POST['treatment_type_code'] ?? '';
            $price = floatval($_POST['price'] ?? 0);

            if (empty($treatment_type_code) || $price < 0) {
                throw new Exception('كود نوع العلاج والسعر مطلوبان');
            }

            // Check if price column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
            if ($stmt->rowCount() == 0) {
                throw new Exception('عمود السعر غير موجود في قاعدة البيانات. يرجى إضافته أولاً.');
            }

            // التحقق من وجود نوع العلاج
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_types WHERE code = ?");
            $stmt->execute([$treatment_type_code]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('نوع العلاج غير موجود');
            }

            // تحديث جميع خيارات العلاج لهذا النوع
            $stmt = $pdo->prepare("
                UPDATE treatment_options
                SET price = ?, updated_at = NOW()
                WHERE treatment_type_code = ?
            ");

            $result = $stmt->execute([$price, $treatment_type_code]);
            $affected_rows = $stmt->rowCount();

            if ($result) {
                $response['success'] = true;
                $response['message'] = "تم تحديث السعر لـ $affected_rows خيار علاج بنجاح";
            } else {
                throw new Exception('فشل في تحديث الأسعار');
            }
            break;

        case 'apply_percentage_change':
            $change_type = $_POST['change_type'] ?? '';
            $percentage = floatval($_POST['percentage'] ?? 0);

            if (!in_array($change_type, ['increase', 'decrease']) || $percentage <= 0) {
                throw new Exception('نوع التغيير والنسبة مطلوبان');
            }

            if ($change_type === 'decrease' && $percentage >= 100) {
                throw new Exception('نسبة الخصم يجب أن تكون أقل من 100%');
            }

            // Check if price column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
            if ($stmt->rowCount() == 0) {
                throw new Exception('عمود السعر غير موجود في قاعدة البيانات. يرجى إضافته أولاً.');
            }

            // تطبيق التغيير النسبي
            if ($change_type === 'increase') {
                $multiplier = 1 + ($percentage / 100);
                $stmt = $pdo->prepare("
                    UPDATE treatment_options
                    SET price = ROUND(price * ?, 2), updated_at = NOW()
                    WHERE price > 0
                ");
            } else {
                $multiplier = 1 - ($percentage / 100);
                $stmt = $pdo->prepare("
                    UPDATE treatment_options
                    SET price = ROUND(price * ?, 2), updated_at = NOW()
                    WHERE price > 0
                ");
            }

            $result = $stmt->execute([$multiplier]);
            $affected_rows = $stmt->rowCount();

            if ($result) {
                $change_text = $change_type === 'increase' ? "زيادة $percentage%" : "خصم $percentage%";
                $response['success'] = true;
                $response['message'] = "تم تطبيق $change_text على $affected_rows خيار علاج بنجاح";
            } else {
                throw new Exception('فشل في تطبيق التغيير النسبي');
            }
            break;

        case 'apply_minimum_price':
            $minimum_price = floatval($_POST['minimum_price'] ?? 0);

            if ($minimum_price < 0) {
                throw new Exception('الحد الأدنى للسعر يجب أن يكون أكبر من أو يساوي صفر');
            }

            // Check if price column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
            if ($stmt->rowCount() == 0) {
                throw new Exception('عمود السعر غير موجود في قاعدة البيانات. يرجى إضافته أولاً.');
            }

            // تطبيق الحد الأدنى للسعر
            $stmt = $pdo->prepare("
                UPDATE treatment_options
                SET price = ?, updated_at = NOW()
                WHERE price < ? OR price IS NULL
            ");

            $result = $stmt->execute([$minimum_price, $minimum_price]);
            $affected_rows = $stmt->rowCount();

            if ($result) {
                $response['success'] = true;
                $response['message'] = "تم تطبيق الحد الأدنى $minimum_price ليرة سورية على $affected_rows خيار علاج";
            } else {
                throw new Exception('فشل في تطبيق الحد الأدنى للسعر');
            }
            break;

        case 'round_prices':
            $round_to = intval($_POST['round_to'] ?? 0);

            if ($round_to <= 0) {
                throw new Exception('قيمة التقريب يجب أن تكون أكبر من صفر');
            }

            // Check if price column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
            if ($stmt->rowCount() == 0) {
                throw new Exception('عمود السعر غير موجود في قاعدة البيانات. يرجى إضافته أولاً.');
            }

            // تقريب الأسعار
            $stmt = $pdo->prepare("
                UPDATE treatment_options
                SET price = ROUND(price / ?) * ?, updated_at = NOW()
                WHERE price > 0
            ");

            $result = $stmt->execute([$round_to, $round_to]);
            $affected_rows = $stmt->rowCount();

            if ($result) {
                $response['success'] = true;
                $response['message'] = "تم تقريب أسعار $affected_rows خيار علاج لأقرب $round_to ليرة سورية";
            } else {
                throw new Exception('فشل في تقريب الأسعار');
            }
            break;

        default:
            throw new Exception('عملية غير مدعومة');
    }
    
} catch (PDOException $e) {
    $response['message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
    $response['debug'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    error_log("PDO Error in treatment_options_management.php: " . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['debug'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'action' => $action ?? 'unknown',
        'trace' => $e->getTraceAsString()
    ];
    error_log("General Error in treatment_options_management.php: " . $e->getMessage());
}

// إرجاع الاستجابة بتنسيق JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>