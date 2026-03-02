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
                SELECT ts.*, CONCAT(tt.name_ar, ' - ', topt.name_ar) as treatment_option_name,
                       tt.name_ar as treatment_type_name, topt.name_ar as option_name
                FROM treatment_stages ts
                LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
                LEFT JOIN treatment_types tt ON topt.treatment_type_code = tt.code
                ORDER BY ts.treatment_option_id ASC, ts.stage_order ASC
            ");
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'get_one':
            $treatment_option_id = (int)($_GET['treatment_option_id'] ?? 0);
            $stage_order = (int)($_GET['stage_order'] ?? 0);

            if ($treatment_option_id <= 0 || $stage_order <= 0) {
                throw new Exception('معرف خيار العلاج وترتيب المرحلة مطلوبان');
            }

            $stmt = $pdo->prepare("
                SELECT ts.*, CONCAT(tt.name_ar, ' - ', topt.name_ar) as treatment_option_name
                FROM treatment_stages ts
                LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
                LEFT JOIN treatment_types tt ON topt.treatment_type_code = tt.code
                WHERE ts.treatment_option_id = ? AND ts.stage_order = ?
            ");
            $stmt->execute([$treatment_option_id, $stage_order]);
            $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$response['data']) {
                throw new Exception('مرحلة العلاج غير موجودة');
            }

            $response['success'] = true;
            break;
            
        case 'get_by_treatment_option':
            $treatment_option_id = (int)($_GET['treatment_option_id'] ?? 0);
            if ($treatment_option_id <= 0) {
                throw new Exception('معرف خيار العلاج مطلوب');
            }

            $stmt = $pdo->prepare("
                SELECT * FROM treatment_stages
                WHERE treatment_option_id = ?
                ORDER BY stage_order ASC
            ");
            $stmt->execute([$treatment_option_id]);
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'add':
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $title_ar = trim($_POST['title_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $duration_ar = trim($_POST['duration_ar'] ?? '');
            $stage_order = (int)($_POST['stage_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            // التحقق من صحة البيانات
            if ($treatment_option_id <= 0 || empty($title_ar)) {
                throw new Exception('معرف خيار العلاج وعنوان المرحلة مطلوبان');
            }

            // التحقق من وجود خيار العلاج
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_options WHERE id = ?");
            $stmt->execute([$treatment_option_id]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('خيار العلاج غير موجود');
            }

            // إذا لم يتم تحديد ترتيب المرحلة، استخدم التالي المتاح
            if ($stage_order <= 0) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(stage_order), 0) + 1
                    FROM treatment_stages
                    WHERE treatment_option_id = ?
                ");
                $stmt->execute([$treatment_option_id]);
                $stage_order = $stmt->fetchColumn();
            } else {
                // التحقق من عدم تكرار ترتيب المرحلة لنفس خيار العلاج
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM treatment_stages
                    WHERE treatment_option_id = ? AND stage_order = ?
                ");
                $stmt->execute([$treatment_option_id, $stage_order]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('ترتيب المرحلة موجود مسبقاً لهذا الخيار');
                }
            }

            // إضافة مرحلة العلاج الجديدة
            $stmt = $pdo->prepare("
                INSERT INTO treatment_stages
                (treatment_option_id, stage_order, title_ar, description_ar, duration_ar, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            $result = $stmt->execute([
                $treatment_option_id, $stage_order, $title_ar, $description_ar, $duration_ar, $is_active
            ]);

            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم إضافة مرحلة العلاج بنجاح';
                $response['data'] = ['stage_order' => $stage_order];
            } else {
                throw new Exception('فشل في إضافة مرحلة العلاج');
            }
            break;
            
        case 'edit':
            $original_treatment_option_id = (int)($_POST['original_treatment_option_id'] ?? 0);
            $original_stage_order = (int)($_POST['original_stage_order'] ?? 0);
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $stage_order = (int)($_POST['stage_order'] ?? 0);
            $title_ar = trim($_POST['title_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $duration_ar = trim($_POST['duration_ar'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($original_treatment_option_id <= 0 || $original_stage_order <= 0 ||
                $treatment_option_id <= 0 || $stage_order <= 0 || empty($title_ar)) {
                throw new Exception('جميع الحقول المطلوبة يجب ملؤها');
            }

            // التحقق من وجود مرحلة العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_stages
                WHERE treatment_option_id = ? AND stage_order = ?
            ");
            $stmt->execute([$original_treatment_option_id, $original_stage_order]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('مرحلة العلاج غير موجودة');
            }

            // التحقق من وجود خيار العلاج الجديد
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_options WHERE id = ?");
            $stmt->execute([$treatment_option_id]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('خيار العلاج الجديد غير موجود');
            }

            // التحقق من عدم تكرار الترتيب الجديد (إذا تم تغييره)
            if ($original_treatment_option_id !== $treatment_option_id || $original_stage_order !== $stage_order) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM treatment_stages
                    WHERE treatment_option_id = ? AND stage_order = ?
                ");
                $stmt->execute([$treatment_option_id, $stage_order]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('ترتيب المرحلة الجديد موجود مسبقاً');
                }
            }

            // تحديث مرحلة العلاج
            $stmt = $pdo->prepare("
                UPDATE treatment_stages
                SET treatment_option_id = ?, stage_order = ?, title_ar = ?, description_ar = ?,
                    duration_ar = ?, is_active = ?, updated_at = NOW()
                WHERE treatment_option_id = ? AND stage_order = ?
            ");

            $result = $stmt->execute([
                $treatment_option_id, $stage_order, $title_ar, $description_ar,
                $duration_ar, $is_active, $original_treatment_option_id, $original_stage_order
            ]);

            if ($result) {
                $response['success'] = true;
                $response['message'] = 'تم تحديث مرحلة العلاج بنجاح';
            } else {
                throw new Exception('فشل في تحديث مرحلة العلاج');
            }
            break;
            
        case 'delete':
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $stage_order = (int)($_POST['stage_order'] ?? 0);

            if ($treatment_option_id <= 0 || $stage_order <= 0) {
                throw new Exception('معرف خيار العلاج وترتيب المرحلة مطلوبان');
            }

            // التحقق من وجود مرحلة العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_stages
                WHERE treatment_option_id = ? AND stage_order = ?
            ");
            $stmt->execute([$treatment_option_id, $stage_order]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('مرحلة العلاج غير موجودة');
            }

            $pdo->beginTransaction();

            try {
                // حذف مرحلة العلاج
                $stmt = $pdo->prepare("
                    DELETE FROM treatment_stages
                    WHERE treatment_option_id = ? AND stage_order = ?
                ");
                $result = $stmt->execute([$treatment_option_id, $stage_order]);

                if (!$result) {
                    throw new Exception('فشل في حذف مرحلة العلاج');
                }

                // إعادة ترتيب المراحل المتبقية
                $stmt = $pdo->prepare("
                    UPDATE treatment_stages
                    SET stage_order = stage_order - 1, updated_at = NOW()
                    WHERE treatment_option_id = ? AND stage_order > ?
                ");
                $stmt->execute([$treatment_option_id, $stage_order]);

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم حذف مرحلة العلاج وإعادة ترتيب المراحل بنجاح';

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'reorder':
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $orders = json_decode($_POST['orders'] ?? '[]', true);

            if ($treatment_option_id <= 0 || empty($orders)) {
                throw new Exception('معرف خيار العلاج وترتيب المراحل مطلوبان');
            }

            $pdo->beginTransaction();

            try {
                foreach ($orders as $index => $order_data) {
                    $new_order = $index + 1; // الترتيب يبدأ من 1
                    $stmt = $pdo->prepare("
                        UPDATE treatment_stages
                        SET stage_order = ?, updated_at = NOW()
                        WHERE treatment_option_id = ? AND stage_order = ?
                    ");
                    $stmt->execute([$new_order, $treatment_option_id, $order_data['original_order']]);
                }

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث ترتيب مراحل العلاج بنجاح';

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'get_treatment_options':
            // جلب جميع خيارات العلاج النشطة للاستخدام في القوائم المنسدلة
            $stmt = $pdo->query("
                SELECT topt.id, topt.name_ar, tt.name_ar as treatment_type_name,
                       CONCAT(tt.name_ar, ' - ', topt.name_ar) as full_name
                FROM treatment_options topt
                LEFT JOIN treatment_types tt ON topt.treatment_type_code = tt.code
                WHERE topt.is_active = 1
                ORDER BY tt.display_order ASC, topt.display_order ASC, topt.name_ar ASC
            ");
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['success'] = true;
            break;
            
        case 'bulk_update_status':
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $stage_orders = json_decode($_POST['stage_orders'] ?? '[]', true);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($treatment_option_id <= 0 || empty($stage_orders)) {
                throw new Exception('معرف خيار العلاج وترتيب المراحل مطلوبة');
            }

            $pdo->beginTransaction();

            try {
                $placeholders = str_repeat('?,', count($stage_orders) - 1) . '?';
                $stmt = $pdo->prepare("
                    UPDATE treatment_stages
                    SET is_active = ?, updated_at = NOW()
                    WHERE treatment_option_id = ? AND stage_order IN ($placeholders)
                ");

                $params = array_merge([$is_active, $treatment_option_id], $stage_orders);
                $stmt->execute($params);

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم تحديث حالة مراحل العلاج بنجاح';

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'duplicate':
            $source_treatment_option_id = (int)($_POST['source_treatment_option_id'] ?? 0);
            $target_treatment_option_id = (int)($_POST['target_treatment_option_id'] ?? 0);

            if ($source_treatment_option_id <= 0 || $target_treatment_option_id <= 0) {
                throw new Exception('معرف خيار العلاج المصدر والهدف مطلوبان');
            }

            // التحقق من وجود خياري العلاج
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM treatment_options
                WHERE id IN (?, ?) AND is_active = 1
            ");
            $stmt->execute([$source_treatment_option_id, $target_treatment_option_id]);
            if ($stmt->fetchColumn() < 2) {
                throw new Exception('أحد خيارات العلاج غير موجود أو غير نشط');
            }

            $pdo->beginTransaction();

            try {
                // حذف المراحل الموجودة في الخيار الهدف
                $stmt = $pdo->prepare("DELETE FROM treatment_stages WHERE treatment_option_id = ?");
                $stmt->execute([$target_treatment_option_id]);

                // جلب مراحل العلاج من المصدر
                $stmt = $pdo->prepare("
                    SELECT stage_order, title_ar, description_ar, duration_ar, is_active
                    FROM treatment_stages
                    WHERE treatment_option_id = ?
                    ORDER BY stage_order ASC
                ");
                $stmt->execute([$source_treatment_option_id]);
                $source_stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($source_stages)) {
                    throw new Exception('لا توجد مراحل في خيار العلاج المصدر');
                }

                // نسخ المراحل إلى الخيار الهدف
                foreach ($source_stages as $stage) {
                    $stmt = $pdo->prepare("
                        INSERT INTO treatment_stages
                        (treatment_option_id, stage_order, title_ar, description_ar, duration_ar, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $target_treatment_option_id,
                        $stage['stage_order'],
                        $stage['title_ar'],
                        $stage['description_ar'],
                        $stage['duration_ar'],
                        $stage['is_active']
                    ]);
                }

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم نسخ ' . count($source_stages) . ' مرحلة علاج بنجاح';

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'insert_stage':
            $treatment_option_id = (int)($_POST['treatment_option_id'] ?? 0);
            $insert_after = (int)($_POST['insert_after'] ?? 0);
            $title_ar = trim($_POST['title_ar'] ?? '');
            $description_ar = trim($_POST['description_ar'] ?? '');
            $duration_ar = trim($_POST['duration_ar'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($treatment_option_id <= 0 || empty($title_ar)) {
                throw new Exception('معرف خيار العلاج وعنوان المرحلة مطلوبان');
            }

            $pdo->beginTransaction();

            try {
                // تحديث ترتيب المراحل الموجودة (رفع الترتيب بواحد للمراحل بعد النقطة المحددة)
                $stmt = $pdo->prepare("
                    UPDATE treatment_stages
                    SET stage_order = stage_order + 1, updated_at = NOW()
                    WHERE treatment_option_id = ? AND stage_order > ?
                ");
                $stmt->execute([$treatment_option_id, $insert_after]);

                // إدراج المرحلة الجديدة
                $new_order = $insert_after + 1;
                $stmt = $pdo->prepare("
                    INSERT INTO treatment_stages
                    (treatment_option_id, stage_order, title_ar, description_ar, duration_ar, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");

                $result = $stmt->execute([
                    $treatment_option_id, $new_order, $title_ar, $description_ar, $duration_ar, $is_active
                ]);

                if (!$result) {
                    throw new Exception('فشل في إدراج مرحلة العلاج');
                }

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'تم إدراج مرحلة العلاج بنجاح';
                $response['data'] = ['stage_order' => $new_order];

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
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