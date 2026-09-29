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
$success_message = '';
$error_message = '';

// Function to create follow-up entries
function createFollowUpEntries($pdo, $treatment_id, $patient_id, $follow_up_period, $follow_up_notes, $priority, $manual_date, $doctor_id) {
    try {
        // Check if follow_ups table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'follow_ups'");
        if ($stmt->rowCount() == 0) {
            return; // Table doesn't exist yet
        }

        // Create automatic follow-up based on period
        if ($follow_up_period && in_array($follow_up_period, [3, 6, 9])) {
            $followup_date = new DateTime();
            $followup_date->modify("+{$follow_up_period} months");

            $reason = "متابعة علاج تلقائية بعد {$follow_up_period} أشهر";
            if ($follow_up_notes) {
                $reason .= " - " . $follow_up_notes;
            }

            $stmt = $pdo->prepare("
                INSERT INTO follow_ups (patient_id, treatment_id, follow_up_type, follow_up_date,
                                      follow_up_reason, priority, notes, created_by)
                VALUES (?, ?, 'treatment', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $patient_id,
                $treatment_id,
                $followup_date->format('Y-m-d'),
                $reason,
                $priority,
                $follow_up_notes,
                $doctor_id
            ]);
        }

        // Create manual follow-up if date is specified
        if ($manual_date) {
            $reason = "موعد متابعة محدد";
            if ($follow_up_notes) {
                $reason .= " - " . $follow_up_notes;
            }

            $stmt = $pdo->prepare("
                INSERT INTO follow_ups (patient_id, treatment_id, follow_up_type, follow_up_date,
                                      follow_up_reason, priority, notes, created_by)
                VALUES (?, ?, 'manual', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $patient_id,
                $treatment_id,
                $manual_date,
                $reason,
                $priority,
                $follow_up_notes,
                $doctor_id
            ]);
        }
    } catch (PDOException $e) {
        // Log error but don't fail the treatment save
        error_log("Failed to create follow-up entries: " . $e->getMessage());
    }
}

// معالجة حفظ تقدم العلاج (بدون إكمال)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_progress') {
    try {
        $treatment_id = $_POST['treatment_id'];
        $treatment_stages_data = $_POST['treatment_stages'] ?? '[]';
        $treatment_details = $_POST['treatment_details'] ?? '';

        // Validate and clean the treatment stages data
        if (empty($treatment_stages_data) || $treatment_stages_data === '[]' || $treatment_stages_data === '') {
            $treatment_stages_data = '[]';
        } else {
            $stages = json_decode($treatment_stages_data, true);
            if (!is_array($stages)) {
                $treatment_stages_data = '[]';
            } else {
                foreach ($stages as &$stage) {
                    if (is_array($stage) && array_key_exists('completedDate', $stage)) {
                        $stage['completedDate'] = normalizeStageDate($stage['completedDate']);
                    }
                }
                unset($stage);
                $treatment_stages_data = json_encode($stages, JSON_UNESCAPED_UNICODE);
            }
        }

        // Build UPDATE query based on available columns
        $update_columns = [];
        $update_params = [];

        // Check which columns exist
        $columns_exist = [];
        $test_columns = ['treatment_stages', 'treatment_details', 'updated_at'];

        foreach ($test_columns as $col) {
            try {
                $pdo->query("SELECT $col FROM treatments LIMIT 1");
                $columns_exist[$col] = true;
            } catch (PDOException $e) {
                $columns_exist[$col] = false;
            }
        }

        if ($columns_exist['treatment_stages']) {
            $update_columns[] = "treatment_stages = ?";
            $update_params[] = $treatment_stages_data;
        }
        if ($columns_exist['treatment_details']) {
            $update_columns[] = "treatment_details = ?";
            $update_params[] = $treatment_details;
        }
        if ($columns_exist['updated_at']) {
            $update_columns[] = "updated_at = NOW()";
        }

        if (empty($update_columns)) {
            $update_columns[] = "updated_at = NOW()";
        }

        // العلاجات مشتركة بين الأطباء: أي طبيب يتابع علاج زميله (يُسجَّل المنفِّذ في activity_log)
        $update_params[] = $treatment_id;

        $sql = "UPDATE treatments SET " . implode(', ', $update_columns) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($update_params);

        if ($result) {
            $success_message = "تم حفظ تقدم العلاج بنجاح";

            // تسجيل النشاط
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, table_name, record_id, description)
                    VALUES (?, 'save_progress', 'treatments', ?, ?)
                ");
                $stmt->execute([$doctor_id, $treatment_id, "تم حفظ تقدم العلاج"]);
            } catch (PDOException $e) {
                // تجاهل خطأ activity_log إذا لم يكن موجود
            }
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في حفظ تقدم العلاج: " . $e->getMessage();
    }
}

// معالجة إكمال العلاج
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'complete_treatment') {
    try {
        $treatment_id = $_POST['treatment_id'];
        $completion_notes = $_POST['completion_notes'] ?? '';
        $treatment_stages_data = $_POST['treatment_stages'] ?? '[]';
        // الطبيب أكّد إنهاء العلاج رغم وجود مراحل لم تُنفَّذ
        $force_complete = !empty($_POST['force_complete']);

        // Check if all treatment stages are completed before allowing completion
        $can_complete = true;
        $incomplete_stages = [];

        if (!empty($treatment_stages_data) && $treatment_stages_data !== '[]' && $treatment_stages_data !== '') {
            $stages = json_decode($treatment_stages_data, true);
            if (is_array($stages) && count($stages) > 0) {
                foreach ($stages as $index => $stage) {
                    if (!isset($stage['completed']) || !$stage['completed']) {
                        $can_complete = false;
                        $incomplete_stages[] = htmlspecialchars($stage['title'] ?? $stage['title_ar'] ?? "المرحلة " . ($index + 1));
                    }
                }
            }
        }

        // If not all stages are completed (and the doctor didn't confirm finishing anyway), show error and stop
        if (!$can_complete && !$force_complete) {
            $error_message = "لا يمكن إكمال العلاج حتى يتم إكمال جميع مراحل العلاج التالية:<br>";
            $error_message .= "• " . implode("<br>• ", $incomplete_stages);
            throw new Exception($error_message);
        }

        // If all stages are completed or no stages exist, proceed with completion
        if (empty($treatment_stages_data) || $treatment_stages_data === '[]' || $treatment_stages_data === '') {
            $treatment_stages_data = '[]';
        } else {
            $stages = json_decode($treatment_stages_data, true);
            if (is_array($stages)) {
                $current_date = date('Y-m-d');
                foreach ($stages as &$stage) {
                    // مراحل أُغلقت بإنهاء العلاج دون تنفيذها تُعلَّم كذلك
                    if (empty($stage['completed'])) {
                        $stage['skipped'] = true;
                        if (empty($stage['notes'])) {
                            $stage['notes'] = 'لم تُنفَّذ - أُغلقت عند إنهاء العلاج';
                        }
                    }
                    $stage['completed'] = true;
                    $stage['completedDate'] = normalizeStageDate($stage['completedDate'] ?? null) ?? $current_date;
                    if (empty($stage['notes'])) {
                        $stage['notes'] = 'تم إكمال هذه المرحلة';
                    }
                }
                unset($stage);
                $treatment_stages_data = json_encode($stages, JSON_UNESCAPED_UNICODE);
            } else {
                // If JSON decode failed, set to empty array
                $treatment_stages_data = '[]';
            }
        }

        // Check which columns exist
        $columns_exist = [];
        $test_columns = ['status', 'completed_at', 'completion_notes', 'treatment_stages', 'treatment_details'];

        foreach ($test_columns as $col) {
            try {
                $pdo->query("SELECT $col FROM treatments LIMIT 1");
                $columns_exist[$col] = true;
            } catch (PDOException $e) {
                $columns_exist[$col] = false;
            }
        }

        // Build UPDATE query based on available columns
        $update_columns = [];
        $update_params = [];

        if ($columns_exist['status']) {
            $update_columns[] = "status = 'completed'";
        }
        if ($columns_exist['completed_at']) {
            $update_columns[] = "completed_at = NOW()";
        }
        if ($columns_exist['completion_notes']) {
            $update_columns[] = "completion_notes = ?";
            $update_params[] = $completion_notes;
        }
        if ($columns_exist['treatment_stages']) {
            $update_columns[] = "treatment_stages = ?";
            $update_params[] = $treatment_stages_data;
        }
        if ($columns_exist['treatment_details']) {
            $update_columns[] = "treatment_details = ?";
            $update_params[] = $_POST['treatment_details'] ?? '';
        }

        // Always update the updated_at if it exists
        try {
            $pdo->query("SELECT updated_at FROM treatments LIMIT 1");
            $update_columns[] = "updated_at = NOW()";
        } catch (PDOException $e) {
            // updated_at column doesn't exist, skip it
        }

        if (empty($update_columns)) {
            $update_columns[] = "updated_at = NOW()";
        }

        // العلاجات مشتركة بين الأطباء: أي طبيب يتابع علاج زميله (يُسجَّل المنفِّذ في activity_log)
        $update_params[] = $treatment_id;

        $sql = "UPDATE treatments SET " . implode(', ', $update_columns) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($update_params);

        if ($result) {
            $success_message = "تم إكمال العلاج بنجاح";

            // تسجيل النشاط
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_log (user_id, action, table_name, record_id, description)
                    VALUES (?, 'complete_treatment', 'treatments', ?, ?)
                ");
                $stmt->execute([$doctor_id, $treatment_id, "تم إكمال العلاج للمريض"]);
            } catch (PDOException $e) {
                // تجاهل خطأ activity_log إذا لم يكن موجود
            }

            // "إنهاء وبدء علاج جديد": الانتقال مباشرة لعلاج جديد لنفس المريض
            if (($_POST['after_complete'] ?? '') === 'new_treatment') {
                $stmt = $pdo->prepare("SELECT patient_id FROM treatments WHERE id = ?");
                $stmt->execute([$treatment_id]);
                $completed_patient_id = $stmt->fetchColumn();
                if ($completed_patient_id) {
                    header("Location: treatment_new.php?patient_id=" . (int)$completed_patient_id . "&completed=1");
                    exit;
                }
            }

            // إعادة توجيه إلى صفحة العلاجات
            header("Location: treatments.php?completed=1");
            exit;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في إكمال العلاج: " . $e->getMessage();
    } catch (Exception $e) {
        // رسالة المراحل غير المكتملة (مُهيّأة أعلاه)
        $error_message = $e->getMessage();
    }
}

if (isset($_GET['completed']) && !$_POST) {
    $success_message = "تم إنهاء العلاج السابق بنجاح، يمكنك الآن تسجيل العلاج الجديد";
}

// معالجة حفظ العلاج الجديد
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_treatment') {
    try {
        $pdo->beginTransaction();
        
        // Check if follow-up columns exist
        $has_followup_columns = true;
        try {
            $pdo->query("SELECT follow_up_period, follow_up_notes FROM treatments LIMIT 1");
        } catch (PDOException $e) {
            $has_followup_columns = false;
        }

        // حفظ العلاج الأساسي
        if ($has_followup_columns) {
            $stmt = $pdo->prepare("
                INSERT INTO treatments (patient_id, appointment_id, treatment_date, treatment_type,
                                      symptoms, diagnosis, treatment_details, medications, cost,
                                      next_appointment_date, notes, doctor_id, teeth_numbers, treatment_stages,
                                      follow_up_period, follow_up_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO treatments (patient_id, appointment_id, treatment_date, treatment_type,
                                      symptoms, diagnosis, treatment_details, medications, cost,
                                      next_appointment_date, notes, doctor_id, teeth_numbers, treatment_stages)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        }
        
        // Ensure proper JSON encoding with error handling
        $selected_teeth = $_POST['selected_teeth'] ?? [];
        if (is_string($selected_teeth)) {
            $selected_teeth = json_decode($selected_teeth, true) ?? [];
        }
        $teeth_numbers = json_encode($selected_teeth, JSON_UNESCAPED_UNICODE);
        
        $treatment_stages_data = $_POST['treatment_stages'] ?? [];
        if (is_string($treatment_stages_data)) {
            $treatment_stages_data = json_decode($treatment_stages_data, true) ?? [];
        }
        $treatment_stages_json = json_encode($treatment_stages_data, JSON_UNESCAPED_UNICODE);
        
        // Handle appointment_id properly - convert empty string to null
        $appointment_id = !empty($_POST['appointment_id']) ? $_POST['appointment_id'] : null;
        // Handle cost properly - convert empty string to null
        $cost = !empty($_POST['cost']) ? $_POST['cost'] : null;
        // Handle next_appointment_date properly - convert empty string to null
        $next_appointment_date = !empty($_POST['next_appointment_date']) ? $_POST['next_appointment_date'] : null;
        
        // Handle follow-up data
        $follow_up_period = !empty($_POST['follow_up_period']) ? intval($_POST['follow_up_period']) : null;
        $follow_up_notes = $_POST['follow_up_notes'] ?? '';

        if ($has_followup_columns) {
            $result = $stmt->execute([
                $_POST['patient_id'],
                $appointment_id,
                $_POST['treatment_date'],
                $_POST['treatment_type'],
                $_POST['symptoms'] ?? '',
                $_POST['diagnosis'],
                $_POST['treatment_details'],
                $_POST['medications'] ?? '',
                $cost,
                $next_appointment_date,
                $_POST['notes'] ?? '',
                $doctor_id,
                $teeth_numbers,
                $treatment_stages_json,
                $follow_up_period,
                $follow_up_notes
            ]);
        } else {
            $result = $stmt->execute([
                $_POST['patient_id'],
                $appointment_id,
                $_POST['treatment_date'],
                $_POST['treatment_type'],
                $_POST['symptoms'] ?? '',
                $_POST['diagnosis'],
                $_POST['treatment_details'],
                $_POST['medications'] ?? '',
                $cost,
                $next_appointment_date,
                $_POST['notes'] ?? '',
                $doctor_id,
                $teeth_numbers,
                $treatment_stages_json
            ]);
        }
        
        if ($result) {
            $treatment_id = $pdo->lastInsertId();
            
            // حفظ تفاصيل الأسنان المعالجة
            if (!empty($_POST['selected_teeth'])) {
                $tooth_stmt = $pdo->prepare("
                    INSERT INTO tooth_treatments (treatment_id, tooth_number, treatment_type, status, notes) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($_POST['selected_teeth'] as $tooth_number) {
                    $tooth_stmt->execute([
                        $treatment_id,
                        $tooth_number,
                        $_POST['treatment_type'],
                        'treated',
                        $_POST['tooth_notes'][$tooth_number] ?? ''
                    ]);
                }
            }
            
            // تحديث حالة الموعد إذا كان مرتبطاً بموعد
            if (!empty($_POST['appointment_id'])) {
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', doctor_entry_time = NOW() WHERE id = ?");
                $stmt->execute([$_POST['appointment_id']]);
            }
            
            // تحديث تاريخ آخر زيارة للمريض
            $stmt = $pdo->prepare("UPDATE patients SET last_visit_date = ? WHERE id = ?");
            $stmt->execute([$_POST['treatment_date'], $_POST['patient_id']]);

            // Create follow-up entries
            createFollowUpEntries($pdo, $treatment_id, $_POST['patient_id'], $follow_up_period, $follow_up_notes, $_POST['follow_up_priority'] ?? 'normal', $next_appointment_date, $doctor_id);

            $pdo->commit();
            $success_message = "تم حفظ العلاج بنجاح";

            // إعادة توجيه إلى صفحة العلاجات
            header("Location: treatments.php?new_treatment=1");
            exit;
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_message = "خطأ في حفظ العلاج: " . $e->getMessage();
    }
}

// جلب قائمة المرضى
try {
    $patients_stmt = $pdo->query("
        SELECT id, name, phone, age, gender, medical_history, allergies 
        FROM patients 
        WHERE status = 'active' 
        ORDER BY name
    ");
    $patients_list = $patients_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients_list = [];
}

// جلب المواعيد اليوم
try {
    $appointments_stmt = $pdo->prepare("
        SELECT a.id, a.appointment_time, p.name as patient_name, p.id as patient_id, a.treatment_type
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        WHERE DATE(a.appointment_date) = CURDATE() 
        AND a.status IN ('scheduled', 'confirmed')
        AND NOT EXISTS (SELECT 1 FROM treatments t WHERE t.appointment_id = a.id)
        ORDER BY a.appointment_time
    ");
    $appointments_stmt->execute();
    $appointments_list = $appointments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $appointments_list = [];
}

// جلب علاجات المريض السابقة إذا تم اختيار مريض
$patient_treatments = [];
if (isset($_GET['patient_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM treatments 
            WHERE patient_id = ?
            ORDER BY treatment_date DESC
            LIMIT 5
        ");
        $stmt->execute([$_GET['patient_id']]);
        $patient_treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $patient_treatments = [];
    }
}

// معاملات محددة مسبقاً
$preselected_appointment = $_GET['appointment_id'] ?? null;
$preselected_patient = $_GET['patient_id'] ?? null;
$view_mode = isset($_GET['complete_id']);
$view_treatment_id = $_GET['complete_id'] ?? null;

// جلب بيانات العلاج للعرض
$view_treatment = null;
if ($view_mode && $view_treatment_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, p.name as patient_name, p.phone as patient_phone, p.age as patient_age
            FROM treatments t
            JOIN patients p ON t.patient_id = p.id
            WHERE t.id = ?
        ");
        $stmt->execute([$view_treatment_id]);
        $view_treatment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$view_treatment) {
            $error_message = "العلاج غير موجود";
            $view_mode = false;
        }
    } catch (PDOException $e) {
        $error_message = "خطأ في جلب بيانات العلاج: " . $e->getMessage();
        $view_mode = false;
    }
}

// Header configuration
if ($view_mode && $view_treatment) {
    $pageTitle = 'إكمال العلاج';
    $pageIcon = 'fas fa-check-circle';
    $pageSubtitle = 'المريض: ' . htmlspecialchars($view_treatment['patient_name'] ?? '');
    $currentPage = 'treatments';
} else {
    $pageTitle = 'علاج جديد';
    $pageIcon = 'fas fa-tooth';
    $pageSubtitle = 'نظام العلاج التفاعلي مع الخريطة البنورامية للأسنان';
    $currentPage = 'treatments';
}

// جلب أنواع العلاج من قاعدة البيانات
try {
    $treatment_types_stmt = $pdo->query("
        SELECT code, name_ar, description_ar, icon_class, icon_color 
        FROM treatment_types 
        WHERE is_active = TRUE 
        ORDER BY display_order, name_ar
    ");
    $treatment_types = $treatment_types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $treatment_types = [];
}

// جلب خيارات العلاج من قاعدة البيانات
try {
    // Check if price column exists
    $columns_stmt = $pdo->query("SHOW COLUMNS FROM treatment_options LIKE 'price'");
    $has_price_column = $columns_stmt->rowCount() > 0;

    if ($has_price_column) {
        $treatment_options_stmt = $pdo->query("
            SELECT id, treatment_type_code, option_code, name_ar, description_ar, price
            FROM treatment_options
            WHERE is_active = TRUE
            ORDER BY treatment_type_code, display_order, name_ar
        ");
    } else {
        $treatment_options_stmt = $pdo->query("
            SELECT id, treatment_type_code, option_code, name_ar, description_ar, 0.00 as price
            FROM treatment_options
            WHERE is_active = TRUE
            ORDER BY treatment_type_code, display_order, name_ar
        ");
    }

    $treatment_options_data = $treatment_options_stmt->fetchAll(PDO::FETCH_ASSOC);

    // تنظيم البيانات حسب نوع العلاج
    $treatment_options = [];
    foreach ($treatment_options_data as $option) {
        // Ensure price field exists
        if (!isset($option['price'])) {
            $option['price'] = '0.00';
        }
        $treatment_options[$option['treatment_type_code']][] = $option;
    }
} catch (PDOException $e) {
    $treatment_options = [];
}

// جلب مراحل العلاج من قاعدة البيانات
try {
    $treatment_stages_stmt = $pdo->query("
        SELECT ts.treatment_option_id, ts.stage_order, ts.title_ar, ts.description_ar, ts.duration_ar,
               topt.id as option_id, topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
        WHERE ts.is_active = TRUE
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
    ");
    $treatment_stages_data = $treatment_stages_stmt->fetchAll(PDO::FETCH_ASSOC);

    // تنظيم البيانات حسب معرف خيار العلاج
    $treatment_stages = [];
    foreach ($treatment_stages_data as $stage) {
        $option_id = $stage['treatment_option_id'];
        if (!isset($treatment_stages[$option_id])) {
            $treatment_stages[$option_id] = [];
        }
        $treatment_stages[$option_id][] = $stage;
    }

    // Debug: Check if data is organized correctly
    error_log("Treatment stages organized: " . json_encode(array_keys($treatment_stages)));
    error_log("Total stages data count: " . count($treatment_stages_data));
    error_log("Organized stages count: " . count($treatment_stages));

} catch (PDOException $e) {
    error_log("Treatment stages query error: " . $e->getMessage());
    $treatment_stages = [];
}

// جلب بيانات الأسنان من قاعدة البيانات
try {
    $teeth_stmt = $pdo->query("
        SELECT t.tooth_number, t.name_ar, t.name_en, t.tooth_type, t.quadrant, t.position_x, t.position_y, t.icon,
               tt.name_ar as type_name, tt.color as type_color 
        FROM teeth_info t 
        LEFT JOIN tooth_types tt ON t.tooth_type = tt.type_code 
        WHERE t.is_active = TRUE 
        ORDER BY t.quadrant, t.tooth_number
    ");
    $teeth_data = $teeth_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنظيم البيانات حسب الربع
    $dental_data = [
        'quadrants' => [
            'upper_right' => ['teeth' => []],
            'upper_left' => ['teeth' => []],
            'lower_left' => ['teeth' => []],
            'lower_right' => ['teeth' => []]
        ]
    ];
    
    foreach ($teeth_data as $tooth) {
        $dental_data['quadrants'][$tooth['quadrant']]['teeth'][] = [
            'number' => $tooth['tooth_number'],
            'name' => $tooth['name_ar'],
            'type' => $tooth['tooth_type'],
            'icon' => $tooth['icon'] ?: '🦷',
            'position' => [
                'x' => (int)$tooth['position_x'],
                'y' => (int)$tooth['position_y']
            ]
        ];
    }
    
    // جلب أنواع الأسنان
    $tooth_types_stmt = $pdo->query("SELECT type_code, name_ar, color FROM tooth_types WHERE is_active = TRUE");
    $tooth_types_data = $tooth_types_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $tooth_types = [];
    foreach ($tooth_types_data as $type) {
        $tooth_types[$type['type_code']] = [
            'name' => $type['name_ar'],
            'color' => $type['color']
        ];
    }
    $dental_data['tooth_types'] = $tooth_types;
    
} catch (PDOException $e) {
    $dental_data = [];
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="dental-chart.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* Custom Dental Chart Styles */
        .dental-chart-custom {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 30px;
            position: relative;
            min-height: 400px;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            overflow: hidden;
        }
        
        .quadrant {
            position: absolute;
            border-radius: 12px;
            opacity: 0.08;
            border: 1px dashed rgba(255,255,255,0.5);
        }
        
        .quadrant-upper-right { 
            top: 20px; left: 20px; 
            width: calc(50% - 30px); height: calc(50% - 30px); 
            background: linear-gradient(135deg, #ef4444, #dc2626); 
        }
        .quadrant-upper-left { 
            top: 20px; right: 20px; 
            width: calc(50% - 30px); height: calc(50% - 30px); 
            background: linear-gradient(135deg, #10b981, #059669); 
        }
        .quadrant-lower-left { 
            bottom: 20px; right: 20px; 
            width: calc(50% - 30px); height: calc(50% - 30px); 
            background: linear-gradient(135deg, #f59e0b, #d97706); 
        }
        .quadrant-lower-right { 
            bottom: 20px; left: 20px; 
            width: calc(50% - 30px); height: calc(50% - 30px); 
            background: linear-gradient(135deg, #3b82f6, #1d4ed8); 
        }
        
        .tooth-element {
            position: absolute;
            width: 35px;
            height: 35px;
            border-radius: 10px;
            background: linear-gradient(135deg, #ffffff, #f8fafc);
            border: 2px solid #d1d5db;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 
                0 4px 6px -1px rgba(0, 0, 0, 0.1),
                0 2px 4px -1px rgba(0, 0, 0, 0.06),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            user-select: none;
            z-index: 20;
        }
        
        .tooth-element:hover {
            transform: scale(1.15) translateY(-2px);
            border-color: #3b82f6;
            box-shadow: 
                0 10px 15px -3px rgba(59, 130, 246, 0.2),
                0 4px 6px -2px rgba(59, 130, 246, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            z-index: 30;
        }
        
        .tooth-element.selected {
            border-color: #1d4ed8;
            border-width: 3px;
            transform: scale(1.2) translateY(-3px);
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            box-shadow: 
                0 20px 25px -5px rgba(29, 78, 216, 0.3),
                0 10px 10px -5px rgba(29, 78, 216, 0.2),
                0 0 0 4px rgba(59, 130, 246, 0.1);
            z-index: 40;
        }
        
        .tooth-number {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 11px;
            font-weight: 700;
            color: #1f2937;
            background: linear-gradient(135deg, #ffffff, #f9fafb);
            padding: 3px 7px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            min-width: 20px;
            text-align: center;
        }
        
        .quadrant-label {
            position: absolute;
            font-weight: 700;
            font-size: 13px;
            z-index: 15;
            background: rgba(255,255,255,0.9);
            padding: 4px 8px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            backdrop-filter: blur(4px);
        }
        
        .quadrant-label.upper-right { 
            top: 25px; left: 25px; 
            color: #dc2626; 
            border-left: 3px solid #ef4444;
        }
        .quadrant-label.upper-left { 
            top: 25px; right: 25px; 
            color: #059669; 
            border-right: 3px solid #10b981;
        }
        .quadrant-label.lower-left { 
            bottom: 25px; right: 25px; 
            color: #d97706; 
            border-right: 3px solid #f59e0b;
        }
        .quadrant-label.lower-right {
            bottom: 25px; left: 25px;
            color: #1d4ed8;
            border-left: 3px solid #3b82f6;
        }

        /* تسمية الربع قابلة للنقر لاختيار كل أسنانه */
        .quadrant-label.quadrant-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .quadrant-label.quadrant-toggle:hover {
            background: #ffffff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        .quadrant-label.quadrant-toggle.all-selected {
            background: #ffffff;
            box-shadow: 0 0 0 2px currentColor;
        }
        .quadrant-label .quadrant-check {
            font-size: 14px;
        }
        
        /* Tooth type specific colors */
        .tooth-element.incisor { 
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-color: #93c5fd;
        }
        .tooth-element.canine { 
            background: linear-gradient(135deg, #d1fae5, #bbf7d0);
            border-color: #86efac;
        }
        .tooth-element.premolar { 
            background: linear-gradient(135deg, #fed7aa, #fdba74);
            border-color: #fb923c;
        }
        .tooth-element.molar { 
            background: linear-gradient(135deg, #fecaca, #fca5a5);
            border-color: #f87171;
        }
        
        /* Treatment Type Cards - Compact Layout */
        .treatment-type-card-compact {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .treatment-type-card-compact:hover {
            border-color: #3b82f6;
            background: #f8fafc;
            transform: translateX(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .treatment-type-card-compact.selected {
            border-color: #1d4ed8;
            background: #dbeafe;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Treatment Options Compact */
        .treatment-options {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .treatment-option {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            gap: 8px;
        }
        
        .treatment-option:hover {
            border-color: #3b82f6;
            background: #f8fafc;
        }
        
        .treatment-option.selected {
            border-color: #1d4ed8;
            background: #dbeafe;
        }
        
        /* Treatment Stages Compact */
        .treatment-stages-compact {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        /* Legend Styles */
        .treatment-legend {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dental-chart-custom {
                min-height: 320px;
                padding: 20px;
            }
            
            .tooth-element {
                width: 28px;
                height: 28px;
                font-size: 14px;
            }
            
            .tooth-number {
                font-size: 9px;
                padding: 2px 5px;
                bottom: -20px;
            }
            
            .quadrant-label {
                font-size: 11px;
                padding: 3px 6px;
            }
            
            /* Make grid single column on mobile */
            .treatment-sections-container .grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .dental-chart-custom {
                min-height: 280px;
                padding: 15px;
            }
            
            .tooth-element {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }
            
            .tooth-number {
                font-size: 8px;
                padding: 1px 4px;
                bottom: -18px;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-1"></i>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="treatmentForm" class="space-y-8">
            <input type="hidden" name="action" value="<?= $view_mode ? 'complete_treatment' : 'save_treatment' ?>">
            <input type="hidden" name="selected_teeth" id="selectedTeethInput">
            <input type="hidden" name="treatment_stages" id="treatmentStagesInput" value="[]">
            <input type="hidden" name="treatment_cost" id="treatmentCostInput" value="0.00">
            <input type="hidden" name="selected_option_code" id="selectedOptionCodeInput" value="">
            <?php if ($view_mode && $view_treatment): ?>
            <input type="hidden" name="treatment_id" value="<?= $view_treatment['id'] ?>">
            <input type="hidden" name="force_complete" id="forceCompleteInput" value="">
            <?php endif; ?>
            
            <!-- Patient Selection -->
            <div class="bg-white rounded-lg shadow-lg p-6 fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-user-md text-green-600 ml-2"></i>
                    اختيار المريض والموعد
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Appointment Selection -->
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">الموعد (اختياري)</label>
                        <select name="appointment_id" id="appointmentSelect" 
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                onchange="selectAppointmentPatient()">
                            <option value="">اختر موعد من اليوم...</option>
                            <?php foreach ($appointments_list as $appointment): ?>
                                <option value="<?= $appointment['id'] ?>" 
                                        data-patient-id="<?= $appointment['patient_id'] ?>"
                                        <?= $preselected_appointment == $appointment['id'] ? 'selected' : '' ?>>
                                    <?= date('H:i', strtotime($appointment['appointment_time'])) ?> - <?= htmlspecialchars($appointment['patient_name']) ?>
                                    - <?= htmlspecialchars($appointment['treatment_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Patient Selection -->
                    <div>
                        <label for="patientSearchInput" class="block text-gray-700 font-semibold mb-2">المريض *</label>
                        <!-- حقل البحث يُبنى فوق هذه القائمة بواسطة assets/js/patient-search.js -->
                            <select name="patient_id" id="patientSelect" required
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    onchange="loadPatientData()">
                                <option value="">اختر مريض...</option>
                                <?php foreach ($patients_list as $patient): ?>
                                    <option value="<?= $patient['id'] ?>"
                                            data-name="<?= htmlspecialchars($patient['name']) ?>"
                                            data-phone="<?= htmlspecialchars($patient['phone'] ?? '') ?>"
                                            data-age="<?= htmlspecialchars($patient['age'] ?? '') ?>"
                                            data-medical-history="<?= htmlspecialchars($patient['medical_history'] ?? '') ?>"
                                            data-allergies="<?= htmlspecialchars($patient['allergies'] ?? '') ?>"
                                            <?= $preselected_patient == $patient['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($patient['name']) ?> - <?= $patient['phone'] ?>
                                        (<?= $patient['age'] ?> سنة)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </div>
                </div>

                <!-- Dental Type Selection -->
                <div class="mt-6">
                    <label class="block text-gray-700 font-semibold mb-3">نوع الأسنان</label>
                    <div class="flex gap-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="dental_type" value="adult" id="adultTeeth" checked
                                   class="ml-2 text-blue-600 focus:ring-blue-500"
                                   onchange="switchDentalType('adult')">
                            <span class="text-gray-700">أسنان دائمة (بالغين)</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="dental_type" value="child" id="childTeeth"
                                   class="ml-2 text-blue-600 focus:ring-blue-500"
                                   onchange="switchDentalType('child')">
                            <span class="text-gray-700">أسنان لبنية (أطفال)</span>
                        </label>
                    </div>
                </div>

                <!-- Patient Medical Alerts -->
                <div id="medicalAlerts" class="mt-4" style="display: none;"></div>
            </div>

            <!-- Dental Chart -->
            <div class="dental-chart-container fade-in">
                <h3 class="text-xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-tooth text-blue-600 ml-2"></i>
                    اختيار الأسنان المراد علاجها
                </h3>
                
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    <div class="lg:col-span-3">
                        <div id="customDentalChart" class="dental-chart-custom"></div>
                        <div id="tooth-info"></div>
                    </div>
                    
                    <div>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h4 class="font-semibold text-gray-800 mb-3">الأسنان المحددة</h4>
                            <div id="selectedTeethList" class="space-y-2">
                                <p class="text-gray-500 text-sm">لم يتم تحديد أي أسنان بعد</p>
                            </div>
                            
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <button type="button" id="selectAllTeeth" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-2 rounded-lg text-sm">
                                    <i class="fas fa-check-double ml-1"></i>
                                    اختيار الكل
                                </button>
                                <button type="button" id="clearSelection" class="w-full bg-gray-500 hover:bg-gray-600 text-white py-2 px-2 rounded-lg text-sm">
                                    مسح التحديد
                                </button>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">
                                <i class="fas fa-info-circle ml-1"></i>
                                لاختيار ربع كامل اضغط على اسم الربع في المخطط
                            </p>
                        </div>
                        
                        <!-- Enhanced Treatment Legend -->
 
                    </div>
                </div>
            </div>

            <!-- Treatment Selection, Details, and Stages in Side-by-Side Layout -->
            <div class="treatment-sections-container fade-in" id="treatmentSectionsContainer" style="display: none;">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Treatment Selection Column -->
                    <div class="treatment-selection-column">
                        <h3 class="text-xl font-bold text-gray-800 mb-6">
                            <i class="fas fa-stethoscope text-purple-600 ml-2"></i>
                            نوع العلاج
                        </h3>
                        
                        <div class="space-y-3">
                            <?php foreach ($treatment_types as $treatment_type): ?>
                                <div class="treatment-type-card-compact" data-treatment="<?= htmlspecialchars($treatment_type['code']) ?>">
                                    <i class="<?= htmlspecialchars($treatment_type['icon_class']) ?> <?= htmlspecialchars($treatment_type['icon_color']) ?> ml-2"></i>
                                    <div class="flex-1">
                                        <div class="font-semibold"><?= htmlspecialchars($treatment_type['name_ar']) ?></div>
                                        <div class="text-xs text-gray-600"><?= htmlspecialchars($treatment_type['description_ar']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="treatment_type" id="selectedTreatmentType" required>
                    </div>

                    <!-- Treatment Details Column -->
                    <div class="treatment-details-column" id="treatmentDetailsColumn" style="display: none;">
                        <h3 class="text-xl font-bold text-gray-800 mb-6">
                            <i class="fas fa-cog text-orange-600 ml-2"></i>
                            تفاصيل العلاج
                        </h3>
                        
                        <div id="treatmentOptionsContainer" class="mb-6">
                            <!-- سيتم ملء هذا القسم بـ JavaScript حسب نوع العلاج المحدد -->
                        </div>

                        <!-- Cost Summary Section -->
                        <div id="costSummaryContainer" class="mb-6" style="display: none;">
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <h4 class="text-lg font-semibold text-green-800 mb-3">
                                    <i class="fas fa-calculator text-green-600 ml-2"></i>
                                    ملخص التكلفة
                                </h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between items-center">
                                        <span class="text-gray-700">خيار العلاج المحدد:</span>
                                        <span id="selectedOptionName" class="font-medium text-gray-900">-</span>
                                    </div>
                                    <div class="flex justify-between items-center text-lg">
                                        <span class="font-semibold text-green-800">التكلفة الإجمالية:</span>
                                        <span id="totalCost" class="font-bold text-green-600 text-xl">0.00 ليرة سورية</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-2">
                                        * التكلفة قابلة للتعديل حسب تعقيد الحالة والعلاجات الإضافية
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Basic Treatment Information -->
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h4 class="text-lg font-semibold text-blue-800 mb-4">
                                <i class="fas fa-info-circle ml-2"></i>
                                معلومات العلاج الأساسية
                            </h4>
                            
                            <div class="space-y-4">
                                <!-- Treatment Date -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">تاريخ العلاج *</label>
                                    <input type="date" name="treatment_date" 
                                           value="<?= date('Y-m-d') ?>" 
                                           required max="<?= date('Y-m-d') ?>"
                                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                                </div>
                                
                                <!-- Cost -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">تكلفة العلاج (ليرة سورية)</label>
                                    <input type="number" name="cost" step="0.01" min="0"
                                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                           placeholder="0.00">
                                </div>
                                
                                <!-- Toggle Button for Additional Details -->
                                <div class="border-t pt-4">
                                    <button type="button" id="toggleDetailedInfo" 
                                            class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                                        <i class="fas fa-plus ml-2" id="toggleIcon"></i>
                                        <span id="toggleText">إضافة معلومات تفصيلية (الأعراض، التشخيص، تفاصيل العلاج، الأدوية)</span>
                                    </button>
                                </div>
                                
                                <!-- Collapsible Additional Details Section -->
                                <div id="detailedInfoSection" class="hidden space-y-4 border-t pt-4">
                                    <!-- Symptoms -->
                                    <div>
                                        <label class="block text-gray-700 font-semibold mb-2 text-sm">الأعراض</label>
                                        <textarea name="symptoms" rows="2"
                                                  class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                                  placeholder="وصف الأعراض التي يعاني منها المريض..."></textarea>
                                    </div>
                                    
                                    <!-- Diagnosis -->
                                    <div>
                                        <label class="block text-gray-700 font-semibold mb-2 text-sm">التشخيص</label>
                                        <textarea name="diagnosis" rows="3"
                                                  class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                                  placeholder="التشخيص الطبي للحالة..."></textarea>
                                    </div>
                                    
                                    <!-- Treatment Details -->
                                    <div>
                                        <label class="block text-gray-700 font-semibold mb-2 text-sm">تفاصيل العلاج المقدم</label>
                                        <textarea name="treatment_details" rows="3"
                                                  class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                                  placeholder="وصف مفصل للإجراءات المتخذة والعلاج المقدم..."></textarea>
                                    </div>
                                    
                                    <!-- Medications -->
                                    <div>
                                        <label class="block text-gray-700 font-semibold mb-2 text-sm">الأدوية الموصوفة</label>
                                        <textarea name="medications" rows="2"
                                                  class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                                  placeholder="الأدوية والمضادات الحيوية الموصوفة مع الجرعات..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Follow-up Information -->
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mt-4">
                            <h4 class="text-lg font-semibold text-green-800 mb-4">
                                <i class="fas fa-calendar-check ml-2"></i>
                                معلومات المتابعة
                            </h4>

                            <div class="space-y-4">
                                <!-- Automatic Follow-up Period -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-3 text-sm">
                                        <i class="fas fa-clock text-green-600 ml-1"></i>
                                        متابعة تلقائية للعلاج
                                    </label>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-gray-50 cursor-pointer transition">
                                            <input type="radio" name="follow_up_period" value="" class="ml-2" checked>
                                            <span class="text-sm">بدون متابعة</span>
                                        </label>
                                        <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-green-50 cursor-pointer transition">
                                            <input type="radio" name="follow_up_period" value="3" class="ml-2">
                                            <span class="text-sm">3 أشهر</span>
                                        </label>
                                        <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-green-50 cursor-pointer transition">
                                            <input type="radio" name="follow_up_period" value="6" class="ml-2">
                                            <span class="text-sm">6 أشهر</span>
                                        </label>
                                        <label class="flex items-center p-3 border border-gray-300 rounded-lg hover:bg-green-50 cursor-pointer transition">
                                            <input type="radio" name="follow_up_period" value="9" class="ml-2">
                                            <span class="text-sm">9 أشهر</span>
                                        </label>
                                    </div>
                                    <div id="followUpPreview" class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800" style="display: none;">
                                        <i class="fas fa-info-circle ml-1"></i>
                                        <span id="followUpPreviewText"></span>
                                    </div>
                                </div>

                                <!-- Manual Follow-up Date -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">
                                        <i class="fas fa-calendar-alt text-blue-600 ml-1"></i>
                                        موعد متابعة محدد (اختياري)
                                    </label>
                                    <input type="date" name="next_appointment_date" id="nextAppointmentDate"
                                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                           class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                           placeholder="لموعد محدد بغض النظر عن المتابعة التلقائية">
                                    <p class="text-xs text-gray-500 mt-1">هذا التاريخ سيكون بالإضافة إلى المتابعة التلقائية إن وجدت</p>
                                </div>

                                <!-- Follow-up Priority -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">
                                        <i class="fas fa-exclamation-triangle text-orange-600 ml-1"></i>
                                        أولوية المتابعة
                                    </label>
                                    <select name="follow_up_priority" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                                        <option value="normal">عادية</option>
                                        <option value="high">عالية</option>
                                        <option value="urgent">عاجلة</option>
                                        <option value="low">منخفضة</option>
                                    </select>
                                </div>

                                <!-- Follow-up Notes -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">
                                        <i class="fas fa-sticky-note text-purple-600 ml-1"></i>
                                        ملاحظات المتابعة
                                    </label>
                                    <textarea name="follow_up_notes" rows="3"
                                              class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                              placeholder="ملاحظات خاصة بالمتابعة (مثل: فحص التئام الجرح، تقييم فعالية العلاج، إلخ)"></textarea>
                                </div>

                                <!-- General Notes -->
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2 text-sm">
                                        <i class="fas fa-comment text-gray-600 ml-1"></i>
                                        ملاحظات إضافية
                                    </label>
                                    <textarea name="notes" rows="2"
                                              class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
                                              placeholder="أي ملاحظات إضافية أو تعليمات للمريض..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Treatment Stages Column -->
                    <div class="treatment-stages-column" id="treatmentStagesColumn" style="display: none;">
                        <h3 class="text-xl font-bold text-gray-800 mb-6">
                            <i class="fas fa-tasks text-indigo-600 ml-2"></i>
                            مراحل العلاج
                        </h3>
                        
                        <div id="treatmentStages" class="treatment-stages-compact">
                            <!-- سيتم ملء هذا القسم بـ JavaScript حسب نوع العلاج المحدد -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex space-x-4 space-x-reverse pt-6" id="submitButtons" style="display: none;">
                <?php if ($view_mode): ?>
                    <!-- Completion Mode Buttons -->
                    <button type="submit" id="completeButton"
                            class="flex-1 bg-green-500 hover:bg-green-600 text-white font-semibold py-4 px-6 rounded-lg transition duration-200 flex items-center justify-center text-lg">
                        <i class="fas fa-check ml-2"></i>
                        إكمال العلاج
                    </button>
                    <button type="submit" id="completeAndNewButton" name="after_complete" value="new_treatment"
                            class="flex-1 bg-indigo-500 hover:bg-indigo-600 text-white font-semibold py-4 px-6 rounded-lg transition duration-200 flex items-center justify-center text-lg">
                        <i class="fas fa-forward ml-2"></i>
                        إنهاء وبدء علاج جديد
                    </button>
                    <button type="button" id="saveProgressButton"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-4 px-6 rounded-lg transition duration-200 flex items-center justify-center text-lg"
                            onclick="saveProgress()">
                        <i class="fas fa-save ml-2"></i>
                        حفظ التقدم
                    </button>
                <?php else: ?>
                    <!-- New Treatment Mode Button -->
                    <button type="submit"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white font-semibold py-4 px-6 rounded-lg transition duration-200 flex items-center justify-center text-lg">
                        <i class="fas fa-save ml-2"></i>
                        حفظ العلاج
                    </button>
                <?php endif; ?>
                <a href="treatments.php"
                   class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 font-semibold py-4 px-6 rounded-lg transition duration-200 text-center text-lg">
                    إلغاء
                </a>
            </div>
        </form>

    </div>

    <script src="../assets/js/patient-search.js"></script>
    <script>
        let dentalChart;
        let treatmentStagesManager;

        // تاريخ اليوم بالتوقيت المحلي بصيغة Y-m-d (toISOString يعطي تاريخ UTC)
        function todayISODate() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        let selectedTreatmentType = '';
        
        // Treatment Stages Management Class with Configuration
        class TreatmentStages {
            constructor(containerId, options = {}) {
                this.container = document.getElementById(containerId);
                this.stages = options.stages || [];
                this.currentStage = 0;
                this.onStageComplete = options.onStageComplete || (() => {});
                this.onStageUpdate = options.onStageUpdate || (() => {});
                this.config = null;
                
                this.loadConfiguration().then(() => {
                    this.init();
                });
            }
            
            async loadConfiguration() {
                try {
                    const response = await fetch('./treatment-stages-config.json');
                    this.config = await response.json();
                } catch (error) {
                    console.warn('Could not load treatment stages configuration, using defaults');
                    this.config = this.getDefaultConfig();
                }
            }
            
            getDefaultConfig() {
                return {
                    stageStyles: {
                        completed: { backgroundColor: "#f0f9ff", borderColor: "#0ea5e9", textColor: "#0c4a6e", icon: "fas fa-check-circle", iconColor: "#0ea5e9" },
                        current: { backgroundColor: "#fefce8", borderColor: "#eab308", textColor: "#713f12", icon: "fas fa-play-circle", iconColor: "#eab308" },
                        upcoming: { backgroundColor: "#f9fafb", borderColor: "#d1d5db", textColor: "#6b7280", icon: "fas fa-clock", iconColor: "#9ca3af" },
                        inProgress: { backgroundColor: "#fef3c7", borderColor: "#f59e0b", textColor: "#92400e", icon: "fas fa-spinner fa-spin", iconColor: "#f59e0b" }
                    },
                    progressBarStyles: {
                        backgroundColor: "#e5e7eb",
                        fillColor: "#3b82f6",
                        height: "8px",
                        borderRadius: "4px"
                    },
                    compactMode: { 
                        enabled: true, 
                        showCompletionDate: false, 
                        showDuration: true, 
                        showNotes: true, 
                        maxNotesLength: 200 
                    },
                    animations: {
                        stageTransition: { duration: "300ms", easing: "cubic-bezier(0.4, 0, 0.2, 1)" },
                        progressBar: { duration: "500ms", easing: "ease-in-out" }
                    },
                    typography: {
                        stageTitle: { fontSize: "16px", fontWeight: "600", lineHeight: "1.5" },
                        stageDescription: { fontSize: "14px", fontWeight: "400", lineHeight: "1.4" },
                        stageDuration: { fontSize: "12px", fontWeight: "500", opacity: "0.7" }
                    },
                    layout: {
                        spacing: { stageGap: "12px", contentPadding: "16px", iconMargin: "8px" },
                        borders: { width: "1px", style: "solid", radius: "8px" }
                    }
                };
            }
            
            init() {
                this.render();
            }
            
            render() {
                // الإعدادات تُحمَّل بشكل غير متزامن؛ init() يعيد الرسم بعد اكتمال تحميلها
                if (!this.config) return;
                const progressConfig = this.config.progressBarStyles;
                const animationConfig = this.config.animations.progressBar;
                
                this.container.innerHTML = `
                    <div class="treatment-stages">
                        <div class="stages-header mb-4">
                            <h4 class="text-lg font-semibold text-gray-800 mb-2">مراحل العلاج</h4>
                            <div class="progress-bar rounded-full" style="background-color: ${progressConfig.backgroundColor}; height: ${progressConfig.height}; border-radius: ${progressConfig.borderRadius};">
                                <div class="progress-fill rounded-full" 
                                     style="background-color: ${progressConfig.fillColor}; height: ${progressConfig.height}; border-radius: ${progressConfig.borderRadius}; width: ${this.getProgressPercentage()}%; transition: width ${animationConfig.duration} ${animationConfig.easing};"></div>
                            </div>
                        </div>
                        <div class="stages-list" style="gap: ${this.config.layout.spacing.stageGap}; display: flex; flex-direction: column;">
                            ${this.stages.map((stage, index) => this.renderStage(stage, index)).join('')}
                        </div>
                        ${this.stages.length > 0 ? this.renderControls() : ''}
                    </div>
                `;
                
                this.attachStageListeners();
            }
            
            renderStage(stage, index) {
                // Check if stage is actually completed (either by stage property or position)
                const isCompleted = stage.completed || index < this.currentStage;
                const isCurrent = index === this.currentStage && !stage.completed;
                const isUpcoming = index > this.currentStage && !stage.completed;
                
                let stageStyle = {};
                let statusIcon = '';
                
                if (isCompleted) {
                    stageStyle = this.config.stageStyles.completed;
                    statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
                } else if (isCurrent) {
                    stageStyle = this.config.stageStyles.current;
                    statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
                } else {
                    stageStyle = this.config.stageStyles.upcoming;
                    statusIcon = `<i class="${stageStyle.icon}" style="color: ${stageStyle.iconColor}"></i>`;
                }
                
                const stageStyles = `background-color: ${stageStyle.backgroundColor}; border-color: ${stageStyle.borderColor}; color: ${stageStyle.textColor};`;
                const titleStyles = `font-size: ${this.config.typography.stageTitle.fontSize}; font-weight: ${this.config.typography.stageTitle.fontWeight};`;
                const descriptionStyles = `font-size: ${this.config.typography.stageDescription.fontSize}; font-weight: ${this.config.typography.stageDescription.fontWeight};`;
                const layoutStyles = `padding: ${this.config.layout.spacing.contentPadding}; border-width: ${this.config.layout.borders.width}; border-style: ${this.config.layout.borders.style}; border-radius: ${this.config.layout.borders.radius};`;
                
                return `
                    <div class="stage-item border" style="${stageStyles} ${layoutStyles}" data-stage-index="${index}">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-reverse" style="gap: ${this.config.layout.spacing.iconMargin};">
                                <div class="stage-icon mt-1">${statusIcon}</div>
                                <div class="stage-content">
                                    <h5 style="${titleStyles}">${stage.title}</h5>
                                    <p style="${descriptionStyles}" class="mt-1">${stage.description}</p>
                                    ${this.config.compactMode.showDuration && stage.duration ? `<span style="font-size: ${this.config.typography.stageDuration.fontSize}; font-weight: ${this.config.typography.stageDuration.fontWeight}; opacity: ${this.config.typography.stageDuration.opacity};">المدة المتوقعة: ${stage.duration}</span>` : ''}
                                </div>
                            </div>
                            <div class="stage-actions">
                                ${isCurrent ? `<button type="button" class="complete-stage-btn btn btn-sm bg-green-500 text-white px-3 py-1 rounded text-xs">إكمال</button>` : ''}
                                ${isCompleted ? `<button type="button" class="edit-stage-btn btn btn-sm bg-gray-500 text-white px-3 py-1 rounded text-xs">تعديل</button>` : ''}
                            </div>
                        </div>
                        ${isCurrent || isCompleted ? this.renderStageDetails(stage, index) : ''}
                    </div>
                `;
            }
            
            renderStageDetails(stage, index) {
                const compactConfig = this.config.compactMode;
                let notesSection = '';
                
                if (compactConfig.showNotes) {
                    const maxLength = compactConfig.maxNotesLength || 200;
                    notesSection = `
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات المرحلة</label>
                            <textarea class="stage-notes w-full p-2 border border-gray-300 rounded text-sm" 
                                      rows="2" maxlength="${maxLength}" 
                                      placeholder="أضف ملاحظات لهذه المرحلة...">${stage.notes || ''}</textarea>
                        </div>
                    `;
                }
                
                return `
                    <div class="stage-details mt-4 p-3 bg-white border border-gray-200 rounded">
                        <div class="grid grid-cols-1 gap-4">
                            ${notesSection}
                        </div>
                    </div>
                `;
            }
            
            renderControls() {
                return `
                    <div class="stages-controls mt-6 flex justify-between items-center">
                        <div class="stage-progress">
                            <span class="text-sm text-gray-600">المرحلة ${this.currentStage + 1} من ${this.stages.length}</span>
                        </div>
                        <div class="stage-buttons space-x-2 space-x-reverse">
                            <button type="button" class="prev-stage-btn btn bg-gray-500 text-white px-4 py-2 rounded text-sm" 
                                    ${this.currentStage === 0 ? 'disabled' : ''}>السابق</button>
                            <button type="button" class="next-stage-btn btn bg-blue-500 text-white px-4 py-2 rounded text-sm"
                                    ${this.currentStage >= this.stages.length - 1 ? 'disabled' : ''}>التالي</button>
                        </div>
                    </div>
                `;
            }
            
            attachStageListeners() {
                // Complete stage buttons
                this.container.querySelectorAll('.complete-stage-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const stageIndex = parseInt(e.target.closest('.stage-item').dataset.stageIndex);
                        this.completeStage(stageIndex);
                    });
                });
                
                // Navigation buttons
                const prevBtn = this.container.querySelector('.prev-stage-btn');
                const nextBtn = this.container.querySelector('.next-stage-btn');
                
                if (prevBtn) {
                    prevBtn.addEventListener('click', () => this.goToPreviousStage());
                }
                
                if (nextBtn) {
                    nextBtn.addEventListener('click', () => this.goToNextStage());
                }
                
                // Stage notes changes
                this.container.querySelectorAll('.stage-notes').forEach(input => {
                    input.addEventListener('change', (e) => {
                        const stageItem = e.target.closest('.stage-item');
                        const stageIndex = parseInt(stageItem.dataset.stageIndex);
                        this.updateStageData(stageIndex, e.target);
                    });
                });
            }
            
            completeStage(stageIndex) {
                if (stageIndex === this.currentStage || !this.stages[stageIndex].completed) {
                    this.stages[stageIndex].completed = true;
                    this.stages[stageIndex].completedDate = todayISODate();

                    // Move to next stage only if not at the final stage
                    if (this.currentStage < this.stages.length - 1) {
                        this.currentStage++;
                    } else {
                        // If this was the final stage, keep currentStage at the last index
                        // but the stage will show as completed due to the stage.completed property
                        this.currentStage = this.stages.length - 1;
                    }

                    this.render();
                    this.onStageComplete(stageIndex, this.stages[stageIndex]);

                    // Update submit button state
                    if (typeof updateSubmitButtonState === 'function') {
                        updateSubmitButtonState();
                    }

                    // Check if all stages are completed
                    if (this.areAllStagesCompleted()) {
                        this.onAllStagesComplete();
                    }
                }
            }

            areAllStagesCompleted() {
                return this.stages.every(stage => stage.completed);
            }

            onAllStagesComplete() {
                // Show completion message
                if (confirm('تم إكمال جميع مراحل العلاج! هل تريد وضع علامة على العلاج كاملاً؟')) {
                    this.markTreatmentAsComplete();
                }
            }

            markTreatmentAsComplete() {
                // Auto-complete the treatment
                const form = document.getElementById('treatmentForm');
                const actionInput = form.querySelector('input[name="action"]');

                // Change form action to complete treatment
                actionInput.value = 'complete_treatment';

                // Update treatment stages input
                updateTreatmentStagesInput();

                // Show completion notice
                const notice = document.createElement('div');
                notice.className = 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4';
                notice.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-check-circle ml-2"></i>
                        <span>تم إكمال جميع مراحل العلاج. سيتم وضع علامة على العلاج كاملاً.</span>
                    </div>
                `;

                form.insertBefore(notice, form.firstChild);

                console.log('Treatment marked as complete due to all stages being completed');
            }
            
            updateStageData(stageIndex, input) {
                const stage = this.stages[stageIndex];
                
                if (input.classList.contains('stage-notes')) {
                    stage.notes = input.value;
                }
                
                this.onStageUpdate(stageIndex, stage);
            }
            
            goToNextStage() {
                if (this.currentStage < this.stages.length - 1) {
                    this.currentStage++;
                    this.render();
                }
            }
            
            goToPreviousStage() {
                if (this.currentStage > 0) {
                    this.currentStage--;
                    this.render();
                }
            }
            
            getProgressPercentage() {
                if (this.stages.length === 0) return 0;
                return (this.currentStage / this.stages.length) * 100;
            }
            
            addStage(stage) {
                this.stages.push(stage);
                this.render();
            }
            
            removeStage(index) {
                this.stages.splice(index, 1);
                if (this.currentStage >= this.stages.length) {
                    this.currentStage = Math.max(0, this.stages.length - 1);
                }
                this.render();
            }
            
            getStagesData() {
                return this.stages;
            }
            
            setStages(stages) {
                this.stages = stages;
                this.currentStage = 0;
                this.render();
            }
        }
        
        // Load treatment data from PHP
        const treatmentOptions = <?= json_encode($treatment_options, JSON_UNESCAPED_UNICODE) ?>;
        const treatmentStages = <?= json_encode($treatment_stages, JSON_UNESCAPED_UNICODE) ?>;

        // Debug logging
        console.log('Loaded treatmentOptions:', treatmentOptions);
        console.log('Loaded treatmentStages:', treatmentStages);

        // Existing treatment data (for completion mode)
        <?php if ($view_mode && $view_treatment): ?>
        const existingTreatment = {
            id: <?= $view_treatment['id'] ?>,
            treatmentDate: '<?= $view_treatment['treatment_date'] ?>',
            treatmentType: '<?= htmlspecialchars($view_treatment['treatment_type'], ENT_QUOTES) ?>',
            symptoms: '<?= htmlspecialchars($view_treatment['symptoms'] ?? '', ENT_QUOTES) ?>',
            diagnosis: '<?= htmlspecialchars($view_treatment['diagnosis'] ?? '', ENT_QUOTES) ?>',
            treatmentDetails: '<?= htmlspecialchars($view_treatment['treatment_details'] ?? '', ENT_QUOTES) ?>',
            medications: '<?= htmlspecialchars($view_treatment['medications'] ?? '', ENT_QUOTES) ?>',
            cost: '<?= $view_treatment['cost'] ?? '' ?>',
            nextAppointmentDate: '<?= $view_treatment['next_appointment_date'] ?? '' ?>',
            notes: '<?= htmlspecialchars($view_treatment['notes'] ?? '', ENT_QUOTES) ?>',
            teethNumbers: <?= !empty($view_treatment['teeth_numbers']) ? $view_treatment['teeth_numbers'] : '[]' ?>,
            treatmentStagesData: <?= !empty($view_treatment['treatment_stages']) ? $view_treatment['treatment_stages'] : '[]' ?>
        };
        console.log('Existing treatment loaded:', existingTreatment);
        console.log('Treatment stages data:', existingTreatment.treatmentStagesData);
        <?php else: ?>
        const existingTreatment = null;
        <?php endif; ?>
        
        // Global variables for custom dental chart
        let customDentalData = null;
        let selectedTeeth = new Set();
        let dentalChartResizeBound = false;
        let patientSearch = null; // assets/js/patient-search.js
        
        document.addEventListener('DOMContentLoaded', function() {
            patientSearch = PatientSearch.attach(document.getElementById('patientSelect'), { inputId: 'patientSearchInput' });

            <?php if ($view_mode): ?>
            // في وضع إكمال العلاج، تحميل بيانات العلاج وإظهار النموذج
            // Pre-fill the form with treatment data if in completion mode
            const patientSelect = document.getElementById('patientSelect');
            if (patientSelect) {
                // Find and select the patient
                for (let option of patientSelect.options) {
                    if (option.textContent.includes('<?= htmlspecialchars($view_treatment['patient_name']) ?>')) {
                        option.selected = true;
                        break;
                    }
                }
                loadPatientData();
            }

            // Load existing treatment data
            loadExistingTreatmentData();

            // إنهاء العلاج: عند وجود مراحل لم تُنفَّذ يُطلب تأكيد الطبيب بدلاً من منعه
            document.getElementById('treatmentForm').addEventListener('submit', function(e) {
                if (this.querySelector('input[name="action"]').value !== 'complete_treatment') return;

                updateTreatmentStagesInput();

                const stages = (window.currentStages && window.currentStages.length)
                    ? window.currentStages
                    : (treatmentStagesManager?.stages || []);
                const pendingStages = stages.filter(stage => !stage.completed);
                const forceInput = document.getElementById('forceCompleteInput');

                if (pendingStages.length > 0) {
                    const names = pendingStages
                        .map(stage => '• ' + (stage.title || stage.title_ar || 'مرحلة غير محددة'))
                        .join('\n');
                    const confirmed = confirm(
                        'لم تكتمل المراحل التالية:\n' + names +
                        '\n\nهل تريد إنهاء العلاج على أي حال؟\nستُسجَّل هذه المراحل على أنها "لم تُنفَّذ".'
                    );
                    if (!confirmed) {
                        e.preventDefault();
                        return;
                    }
                    if (forceInput) forceInput.value = '1';
                } else if (forceInput) {
                    forceInput.value = '';
                }
            });

            // Ensure UI elements are visible for completion mode
            setTimeout(() => {
                console.log('إظهار عناصر واجهة المستخدم في وضع الإكمال');
                document.getElementById('treatmentDetailsColumn').style.display = 'block';
                document.getElementById('treatmentStagesColumn').style.display = 'block';
                document.getElementById('submitButtons').style.display = 'flex';

                // If we have existing treatment stages, show them immediately
                if (existingTreatment && existingTreatment.treatmentStagesData &&
                    Array.isArray(existingTreatment.treatmentStagesData) &&
                    existingTreatment.treatmentStagesData.length > 0) {
                    console.log('إظهار مراحل العلاج الموجودة فوراً');

                    // Normalize the existing treatment stages data
                    const normalizedExistingStages = existingTreatment.treatmentStagesData.map(stage => {
                        return {
                            title: stage.title || stage.title_ar || 'مرحلة غير محددة',
                            description: stage.description || stage.description_ar || '',
                            duration: stage.duration || stage.duration_ar || '',
                            completed: stage.completed || false,
                            completedDate: stage.completedDate || stage.completed_date || null,
                            notes: stage.notes || ''
                        };
                    });

                    console.log('Normalized existing stages:', normalizedExistingStages);

                    // Create stages manager if not exists
                    if (!treatmentStagesManager) {
                        treatmentStagesManager = new TreatmentStages('treatmentStages', {
                            stages: normalizedExistingStages,
                            onStageComplete: function(stageIndex, stage) {
                                console.log('Stage completed:', stage);
                                updateTreatmentStagesInput();
                            },
                            onStageUpdate: function(stageIndex, stage) {
                                updateTreatmentStagesInput();
                            }
                        });
                        treatmentStagesManager.render();

                        // Update the hidden input with stages data
                        updateTreatmentStagesInput();

                        // Additional debug info
                        console.log('مراحل العلاج المحملة:', normalizedExistingStages.length, 'مرحلة');
                        console.log('تفاصيل المراحل:', normalizedExistingStages);
                    }
                } else {
                    console.log('لا توجد مراحل علاج موجودة، إظهار رسالة إعلامية');
                    // Show message that no stages are available
                    document.getElementById('treatmentStages').innerHTML =
                        '<div style="text-align: center; padding: 20px; color: #666;">' +
                        '<div class="bg-blue-50 border border-blue-200 rounded-lg p-6">' +
                        '<i class="fas fa-info-circle text-blue-600 text-2xl mb-3"></i>' +
                        '<h4 class="text-lg font-semibold text-blue-800 mb-2">لا توجد مراحل محددة لهذا العلاج</h4>' +
                        '<p class="text-blue-700 mb-4">هذا العلاج لا يحتوي على مراحل محددة مسبقاً.</p>' +
                        '<p class="text-blue-600 text-sm">يمكنك إكمال العلاج مباشرة باستخدام الزر أدناه.</p>' +
                        '</div>' +
                        '</div>';
                }
            }, 100);

            <?php else: ?>
            // Load dental data and initialize chart
            const initialDentalType = document.querySelector('input[name="dental_type"]:checked')?.value || 'adult';
            loadDentalData(initialDentalType).then(() => {
                initializeCustomDentalChart();
            });

            // Initialize event listeners
            initializeEventListeners();

            // Load preselected patient data if available
            if (document.getElementById('patientSelect').value) {
                loadPatientData();
            }
            <?php endif; ?>
        });
        
        // Load dental numbering data from JSON
        async function loadDentalData(dentalType = 'adult') {
            try {
                const fileName = dentalType === 'child' ? 'dental-numbering-primary.json' : 'dental-numbering.json';
                const response = await fetch(fileName);
                customDentalData = await response.json();
            } catch (error) {
                console.error('Error loading dental data:', error);
                // Fallback data if JSON fails to load
                if (dentalType === 'child') {
                    const letters = ['A', 'B', 'C', 'D', 'E'];
                    customDentalData = {
                        quadrants: {
                            upper_right: { teeth: letters.map((letter, i) => ({number: `${letter}1`, name: 'سن لبني', type: 'primary_molar'})) },
                            upper_left: { teeth: letters.map((letter, i) => ({number: `${letter}2`, name: 'سن لبني', type: 'primary_molar'})) },
                            lower_left: { teeth: letters.map((letter, i) => ({number: `${letter}3`, name: 'سن لبني', type: 'primary_molar'})) },
                            lower_right: { teeth: letters.map((letter, i) => ({number: `${letter}4`, name: 'سن لبني', type: 'primary_molar'})) }
                        }
                    };
                } else {
                    customDentalData = {
                        quadrants: {
                            upper_right: { teeth: Array.from({length: 8}, (_, i) => ({number: (18-i).toString(), name: 'سن', type: 'molar'})) },
                            upper_left: { teeth: Array.from({length: 8}, (_, i) => ({number: (21+i).toString(), name: 'سن', type: 'molar'})) },
                            lower_left: { teeth: Array.from({length: 8}, (_, i) => ({number: (31+i).toString(), name: 'سن', type: 'molar'})) },
                            lower_right: { teeth: Array.from({length: 8}, (_, i) => ({number: (48-i).toString(), name: 'سن', type: 'molar'})) }
                        }
                    };
                }
            }
        }

        // Switch between adult and child dental types
        function switchDentalType(dentalType) {
            console.log('Switching dental type to:', dentalType);

            // Show immediate feedback
            const chartTitle = document.querySelector('.dental-chart-container h3');
            if (chartTitle) {
                const loadingIcon = '<i class="fas fa-spinner fa-spin text-blue-600 ml-2"></i>';
                const loadingText = dentalType === 'child' ? 'جاري تحميل الأسنان اللبنية...' : 'جاري تحميل الأسنان الدائمة...';
                chartTitle.innerHTML = `${loadingIcon}${loadingText}`;
            }

            // Clear current selection
            selectedTeeth.clear();
            updateSelectedTeethList(selectedTeeth);

            // Load new dental data and reinitialize chart
            loadDentalData(dentalType).then(() => {
                console.log('Loaded dental data for:', dentalType);
                initializeCustomDentalChart();

                // Update chart title based on type
                if (chartTitle) {
                    const icon = dentalType === 'child' ? 'fas fa-child' : 'fas fa-tooth';
                    const text = dentalType === 'child' ? 'اختيار الأسنان اللبنية المراد علاجها' : 'اختيار الأسنان المراد علاجها';
                    chartTitle.innerHTML = `<i class="${icon} text-blue-600 ml-2"></i>${text}`;
                }

                console.log('Chart updated successfully for dental type:', dentalType);
            }).catch(error => {
                console.error('Error switching dental type:', error);
                if (chartTitle) {
                    chartTitle.innerHTML = '<i class="fas fa-exclamation-triangle text-red-600 ml-2"></i>خطأ في تحميل بيانات الأسنان';
                }
            });
        }

        // Initialize custom dental chart
        function initializeCustomDentalChart() {
            const chartContainer = document.getElementById('customDentalChart');
            chartContainer.innerHTML = '';
            
            // Add quadrant backgrounds
            const quadrantBGs = [
                'quadrant-upper-right', 'quadrant-upper-left', 
                'quadrant-lower-left', 'quadrant-lower-right'
            ];
            quadrantBGs.forEach(className => {
                const quad = document.createElement('div');
                quad.className = `quadrant ${className}`;
                chartContainer.appendChild(quad);
            });
            
            // Add quadrant labels — each label toggles selection of all teeth in its quadrant
            const quadrantLabels = [
                {key: 'upper_right', class: 'upper-right', number: 1},
                {key: 'upper_left', class: 'upper-left', number: 2},
                {key: 'lower_left', class: 'lower-left', number: 3},
                {key: 'lower_right', class: 'lower-right', number: 4}
            ];

            quadrantLabels.forEach(label => {
                const teeth = customDentalData.quadrants[label.key]?.teeth || [];
                if (teeth.length === 0) return;

                const labelEl = document.createElement('button');
                labelEl.type = 'button';
                labelEl.className = `quadrant-label quadrant-toggle ${label.class}`;
                labelEl.dataset.quadrant = label.key;
                labelEl.title = 'اضغط لاختيار أو إلغاء اختيار كل أسنان هذا الربع';
                labelEl.innerHTML = `<i class="quadrant-check far fa-square"></i>` +
                    `<span>الربع ${label.number} <span dir="ltr">(${escapeHtml(teeth[0].number)}-${escapeHtml(teeth[teeth.length - 1].number)})</span></span>`;
                labelEl.addEventListener('click', () => toggleQuadrantSelection(label.key));
                chartContainer.appendChild(labelEl);
            });

            // Add teeth for each quadrant
            Object.keys(customDentalData.quadrants).forEach(quadrantKey => {
                const quadrant = customDentalData.quadrants[quadrantKey];
                quadrant.teeth.forEach((tooth, index) => {
                    createToothElement(tooth, quadrantKey, index, chartContainer);
                });
            });

            updateQuadrantToggles();

            // Add resize handler for responsive positioning (once — this function re-runs on every resize)
            if (!dentalChartResizeBound) {
                dentalChartResizeBound = true;
                window.addEventListener('resize', debounce(function() {
                    initializeCustomDentalChart();
                }, 250));
            }
        }

        function getChartTeethNumbers(quadrantKey = null) {
            if (!customDentalData || !customDentalData.quadrants) return [];
            const quadrants = quadrantKey ? [customDentalData.quadrants[quadrantKey]] : Object.values(customDentalData.quadrants);
            return quadrants.filter(Boolean).flatMap(quadrant => quadrant.teeth.map(tooth => tooth.number));
        }

        // Select or deselect a group of teeth, then refresh everything that depends on the selection
        function setTeethSelected(toothNumbers, selected) {
            toothNumbers.forEach(number => selected ? selectedTeeth.add(number) : selectedTeeth.delete(number));
            document.querySelectorAll('#customDentalChart .tooth-element').forEach(el => {
                el.classList.toggle('selected', selectedTeeth.has(el.dataset.toothNumber));
            });
            updateSelectedTeethList(selectedTeeth);
            toggleTreatmentSelection(selectedTeeth.size > 0);
            updateQuadrantToggles();
        }

        function toggleQuadrantSelection(quadrantKey) {
            const teeth = getChartTeethNumbers(quadrantKey);
            const allSelected = teeth.length > 0 && teeth.every(number => selectedTeeth.has(number));
            setTeethSelected(teeth, !allSelected);
        }

        // Quadrant label checkbox: empty / partial / all selected
        function updateQuadrantToggles() {
            document.querySelectorAll('#customDentalChart .quadrant-toggle').forEach(labelEl => {
                const teeth = getChartTeethNumbers(labelEl.dataset.quadrant);
                const count = teeth.filter(number => selectedTeeth.has(number)).length;
                const allSelected = count > 0 && count === teeth.length;
                labelEl.querySelector('.quadrant-check').className = 'quadrant-check ' +
                    (allSelected ? 'fas fa-check-square' : (count > 0 ? 'fas fa-minus-square' : 'far fa-square'));
                labelEl.classList.toggle('all-selected', allSelected);
                labelEl.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
            });
        }
        
        // Debounce function for resize handler
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Create individual tooth element
        function createToothElement(tooth, quadrantKey, index, container) {
            const toothEl = document.createElement('div');
            toothEl.className = `tooth-element ${tooth.type}`;
            toothEl.dataset.toothNumber = tooth.number;
            toothEl.dataset.toothType = tooth.type;
            toothEl.dataset.toothName = tooth.name;
            // keep the highlight when the chart is redrawn (e.g. on resize)
            if (selectedTeeth.has(tooth.number)) {
                toothEl.classList.add('selected');
            }

            // Add tooth icon
            toothEl.innerHTML = tooth.icon || '🦷';
            
            // Add tooth number label
            const numberLabel = document.createElement('div');
            numberLabel.className = 'tooth-number';
            numberLabel.textContent = tooth.number;
            toothEl.appendChild(numberLabel);
            
            // Position tooth based on quadrant and index
            const position = calculateToothPosition(quadrantKey, index);
            toothEl.style.left = position.x + 'px';
            toothEl.style.top = position.y + 'px';
            
            // Add click event
            toothEl.addEventListener('click', function() {
                toggleToothSelection(tooth.number, toothEl);
            });
            
            // Add hover tooltip
            toothEl.addEventListener('mouseenter', function(e) {
                showToothTooltip(tooth, e);
            });
            
            toothEl.addEventListener('mouseleave', function() {
                hideToothTooltip();
            });
            
            container.appendChild(toothEl);
        }
        
        // Calculate tooth position based on quadrant
        function calculateToothPosition(quadrantKey, index) {
            // Get chart container dimensions for responsive positioning
            const chartContainer = document.getElementById('customDentalChart');
            const chartWidth = chartContainer?.offsetWidth || 800;
            const chartHeight = chartContainer?.offsetHeight || 400;
            
            // Base positions as percentages of chart size
            const positions = {
                upper_right: { 
                    startX: chartWidth * 0.45, 
                    startY: chartHeight * 0.2, 
                    stepX: -chartWidth * 0.04, 
                    stepY: chartHeight * 0.01 
                },
                upper_left: { 
                    startX: chartWidth * 0.55, 
                    startY: chartHeight * 0.2, 
                    stepX: chartWidth * 0.04, 
                    stepY: chartHeight * 0.01 
                },
                lower_left: { 
                    startX: chartWidth * 0.55, 
                    startY: chartHeight * 0.65, 
                    stepX: chartWidth * 0.04, 
                    stepY: -chartHeight * 0.01 
                },
                lower_right: { 
                    startX: chartWidth * 0.45, 
                    startY: chartHeight * 0.65, 
                    stepX: -chartWidth * 0.04, 
                    stepY: -chartHeight * 0.01 
                }
            };
            
            const pos = positions[quadrantKey];
            return {
                x: Math.round(pos.startX + (index * pos.stepX)),
                y: Math.round(pos.startY + (index * pos.stepY))
            };
        }
        
        // Toggle tooth selection
        function toggleToothSelection(toothNumber, toothElement) {
            if (selectedTeeth.has(toothNumber)) {
                selectedTeeth.delete(toothNumber);
                toothElement.classList.remove('selected');
            } else {
                selectedTeeth.add(toothNumber);
                toothElement.classList.add('selected');
            }
            
            updateSelectedTeethList(selectedTeeth);
            toggleTreatmentSelection(selectedTeeth.size > 0);
            updateQuadrantToggles();
        }

        // Show tooth tooltip
        function showToothTooltip(tooth, event) {
            const tooltip = document.getElementById('tooth-info');
            if (tooltip) {
                tooltip.innerHTML = `
                    <div class="p-3 bg-white border border-gray-200 rounded-lg shadow-lg">
                        <h4 class="font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-tooth text-blue-600 ml-2"></i>
                            السن رقم ${tooth.number}
                        </h4>
                        <p class="text-sm text-gray-600">${tooth.name}</p>
                        <p class="text-sm"><span class="font-medium">النوع:</span> ${customDentalData.tooth_types?.[tooth.type]?.name || tooth.type}</p>
                        <p class="text-sm"><span class="font-medium">الحالة:</span> سليم</p>
                    </div>
                `;
                tooltip.style.position = 'fixed';
                tooltip.style.left = (event.pageX + 10) + 'px';
                tooltip.style.top = (event.pageY - 10) + 'px';
                tooltip.style.display = 'block';
                tooltip.style.zIndex = '1000';
            }
        }
        
        // Hide tooth tooltip
        function hideToothTooltip() {
            const tooltip = document.getElementById('tooth-info');
            if (tooltip) {
                tooltip.style.display = 'none';
            }
        }
        
        // Clear all tooth selections
        function clearAllToothSelections() {
            selectedTeeth.clear();
            document.querySelectorAll('.tooth-element.selected').forEach(tooth => {
                tooth.classList.remove('selected');
            });
            updateQuadrantToggles();
        }
        
        // Get selected teeth array (for compatibility)
        function getSelectedTeeth() {
            return Array.from(selectedTeeth);
        }
        
        function initializeEventListeners() {
            // Select all teeth button
            document.getElementById('selectAllTeeth').addEventListener('click', function() {
                setTeethSelected(getChartTeethNumbers(), true);
            });

            // Clear selection button
            document.getElementById('clearSelection').addEventListener('click', function() {
                clearAllToothSelections();
                updateSelectedTeethList(new Set());
                toggleTreatmentSelection(false);
            });
            
            // Toggle detailed information section
            document.getElementById('toggleDetailedInfo').addEventListener('click', function() {
                const section = document.getElementById('detailedInfoSection');
                const icon = document.getElementById('toggleIcon');
                const text = document.getElementById('toggleText');
                
                if (section.classList.contains('hidden')) {
                    section.classList.remove('hidden');
                    section.classList.add('fade-in');
                    icon.classList.remove('fa-plus');
                    icon.classList.add('fa-minus');
                    text.textContent = 'إخفاء المعلومات التفصيلية';
                } else {
                    section.classList.add('hidden');
                    section.classList.remove('fade-in');
                    icon.classList.remove('fa-minus');
                    icon.classList.add('fa-plus');
                    text.textContent = 'إضافة معلومات تفصيلية (الأعراض، التشخيص، تفاصيل العلاج، الأدوية)';
                }
            });
            
            // Treatment type cards
            document.querySelectorAll('.treatment-type-card-compact').forEach(card => {
                card.addEventListener('click', function() {
                    selectTreatmentType(this.dataset.treatment);
                });
            });
            
            // Form submission
            document.getElementById('treatmentForm').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }

                // Always update treatment stages input before submitting
                console.log('تحديث بيانات مراحل العلاج قبل الإرسال');
                updateTreatmentStagesInput();

                // If this is treatment completion, mark all stages as completed
                const action = document.querySelector('input[name="action"]').value;
                if (action === 'complete_treatment' && treatmentStagesManager) {
                    completeAllTreatmentStages();
                }
            });
        }
        
        function selectAppointmentPatient() {
            const appointmentSelect = document.getElementById('appointmentSelect');
            const patientSelect = document.getElementById('patientSelect');
            
            if (appointmentSelect.value) {
                const selectedOption = appointmentSelect.options[appointmentSelect.selectedIndex];
                const patientId = selectedOption.getAttribute('data-patient-id');
                
                if (patientId) {
                    patientSelect.value = patientId;
                    loadPatientData();
                }
            }
        }
        
        function loadPatientData() {
            const patientSelect = document.getElementById('patientSelect');
            const medicalAlerts = document.getElementById('medicalAlerts');

            patientSearch?.sync();

            if (!patientSelect.value) {
                medicalAlerts.style.display = 'none';
                return;
            }

            if (patientSelect.value) {
                const selectedOption = patientSelect.options[patientSelect.selectedIndex];
                const medicalHistory = selectedOption.getAttribute('data-medical-history');
                const allergies = selectedOption.getAttribute('data-allergies');
                
                if (medicalHistory || allergies) {
                    let alertsHtml = '<div class="medical-alert">';
                    alertsHtml += '<div class="medical-alert-header"><i class="fas fa-exclamation-triangle ml-1"></i>تنبيه طبي مهم</div>';
                    alertsHtml += '<div class="medical-alert-content">';
                    
                    if (medicalHistory) {
                        alertsHtml += '<p><strong>التاريخ المرضي:</strong> ' + medicalHistory + '</p>';
                    }
                    if (allergies) {
                        alertsHtml += '<p><strong>الحساسية:</strong> ' + allergies + '</p>';
                    }
                    
                    alertsHtml += '</div></div>';
                    medicalAlerts.innerHTML = alertsHtml;
                    medicalAlerts.style.display = 'block';
                } else {
                    medicalAlerts.style.display = 'none';
                }
            }
        }
        
        function updateSelectedTeethList(selectedTeeth) {
            const listContainer = document.getElementById('selectedTeethList');
            const selectedTeethArray = Array.from(selectedTeeth);
            
            // Update hidden input with error handling
            try {
                document.getElementById('selectedTeethInput').value = JSON.stringify(selectedTeethArray);
            } catch (error) {
                console.error('Error encoding selected teeth to JSON:', error);
                document.getElementById('selectedTeethInput').value = '[]';
            }
            
            if (selectedTeethArray.length === 0) {
                listContainer.innerHTML = '<p class="text-gray-500 text-sm">لم يتم تحديد أي أسنان بعد</p>';
                return;
            }
            
            // compact chips, sorted, so selecting a whole quadrant or all teeth stays readable
            const sortedTeeth = [...selectedTeethArray].sort((a, b) => String(a).localeCompare(String(b), 'en', { numeric: true }));
            let html = '<div class="flex flex-wrap gap-1">';
            sortedTeeth.forEach(toothId => {
                html += `<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-medium">${escapeHtml(toothId)}</span>`;
            });
            html += '</div>';
            
            html += `<div class="mt-2 text-xs text-gray-600">المجموع: ${selectedTeethArray.length} سن</div>`;
            listContainer.innerHTML = html;
        }
        
        function toggleTreatmentSelection(show) {
            const treatmentSections = document.getElementById('treatmentSectionsContainer');
            treatmentSections.style.display = show ? 'block' : 'none';
            
            if (!show) {
                // Reset treatment selection
                document.querySelectorAll('.treatment-type-card-compact').forEach(card => {
                    card.classList.remove('selected');
                });
                document.getElementById('selectedTreatmentType').value = '';
                document.getElementById('treatmentDetailsColumn').style.display = 'none';
                document.getElementById('treatmentStagesColumn').style.display = 'none';
                document.getElementById('submitButtons').style.display = 'none';
            }
        }
        
        function selectTreatmentType(treatmentType) {
            // Update UI
            document.querySelectorAll('.treatment-type-card-compact').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`[data-treatment="${treatmentType}"]`).classList.add('selected');
            
            // Update form
            document.getElementById('selectedTreatmentType').value = treatmentType;
            selectedTreatmentType = treatmentType;
            
            // Hide cost summary when treatment type changes
            hideCostSummary();

            // Show treatment options
            showTreatmentOptions(treatmentType);
            
            // Show treatment details column
            document.getElementById('treatmentDetailsColumn').style.display = 'block';
            
            // إظهار عمود مراحل العلاج دائماً
            console.log('إظهار عمود مراحل العلاج');
            document.getElementById('treatmentStagesColumn').style.display = 'block';
            
            // Show submit buttons
            document.getElementById('submitButtons').style.display = 'flex';
        }
        
        function showTreatmentOptions(treatmentType) {
            const container = document.getElementById('treatmentOptionsContainer');
            const options = treatmentOptions[treatmentType];
            
            if (!options || options.length === 0) return;
            
            let html = `<h4 class="text-lg font-semibold text-gray-800 mb-4">خيارات ${treatmentType}</h4>`;
            html += '<div class="treatment-options">';
            
            options.forEach(option => {
                const price = parseFloat(option.price || 0);
                const priceDisplay = price > 0 ? price.toFixed(2) + ' ليرة سورية' : 'مجاني';
                const priceClass = price > 0 ? 'text-green-600' : 'text-gray-500';

                html += `
                    <div class="treatment-option" data-option="${option.option_code}" data-option-id="${option.id}" data-price="${price}">
                        <input type="radio" name="treatment_option" value="${option.option_code}" data-option-id="${option.id}" id="opt_${option.option_code}">
                        <label for="opt_${option.option_code}" class="cursor-pointer">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="font-medium">${option.name_ar}</div>
                                    <div class="text-xs text-gray-600">${option.description_ar || ''}</div>
                                </div>
                                <div class="text-sm font-semibold ${priceClass} ml-3">
                                    <i class="fas fa-money-bill-wave ml-1"></i>
                                    ${priceDisplay}
                                </div>
                            </div>
                        </label>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
            
            // Add event listeners to options
            container.querySelectorAll('.treatment-option').forEach(option => {
                option.addEventListener('click', function(e) {
                    if (e.target.type !== 'radio') {
                        const radio = this.querySelector('input[type="radio"]');
                        radio.checked = true;

                        // Remove selected class from all options
                        container.querySelectorAll('.treatment-option').forEach(opt => {
                            opt.classList.remove('selected');
                        });

                        // Add selected class to this option
                        this.classList.add('selected');

                        // Update the treatment_details field with the selected option
                        const optionText = this.querySelector('label .font-medium').textContent;
                        const treatmentDetailsInput = document.querySelector('textarea[name="treatment_details"]');
                        if (treatmentDetailsInput) {
                            treatmentDetailsInput.value = optionText;
                        }

                        // Update cost calculation
                        updateCostSummary(this, optionText);

                        // Show treatment stages for selected option
                        // استخدام المتغير العام بدلاً من البحث في DOM
                        const currentTreatmentType = selectedTreatmentType || document.getElementById('selectedTreatmentType')?.value;
                        const selectedOptionCode = this.dataset.option;
                        const selectedOptionId = this.dataset.optionId;

                        console.log('=== Treatment Option Click Event ===');
                        console.log('currentTreatmentType:', currentTreatmentType);
                        console.log('selectedOptionCode:', selectedOptionCode);
                        console.log('selectedOptionId:', selectedOptionId);
                        console.log('this.dataset:', this.dataset);

                        // استدعاء showTreatmentStages حتى لو كان بعض البيانات مفقودة
                        console.log('✓ Calling showTreatmentStages...');
                        showTreatmentStages(currentTreatmentType || 'examination', selectedOptionCode || 'default', selectedOptionId || 'test');
                    }
                });

                // Also handle direct radio button clicks
                const radio = option.querySelector('input[type="radio"]');
                radio.addEventListener('change', function() {
                    // Remove selected class from all options
                    container.querySelectorAll('.treatment-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });

                    // Add selected class to the parent of the checked radio
                    if (this.checked) {
                        option.classList.add('selected');

                        // Update the treatment_details field with the selected option
                        const optionText = option.querySelector('label .font-medium').textContent;
                        const treatmentDetailsInput = document.querySelector('textarea[name="treatment_details"]');
                        if (treatmentDetailsInput) {
                            treatmentDetailsInput.value = optionText;
                        }

                        // Update cost calculation
                        updateCostSummary(option, optionText);

                        // Show treatment stages for selected option
                        const currentTreatmentType = selectedTreatmentType || document.getElementById('selectedTreatmentType')?.value;
                        const selectedOptionCode = option.dataset.option;
                        const selectedOptionId = option.dataset.optionId;

                        console.log('Radio button click - calling showTreatmentStages');
                        showTreatmentStages(currentTreatmentType || 'examination', selectedOptionCode || 'default', selectedOptionId || 'test');
                    }
                });
            });
        }

        function updateCostSummary(optionElement, optionName) {
            const price = parseFloat(optionElement.dataset.price || 0);
            const optionCode = optionElement.dataset.option;
            const costSummaryContainer = document.getElementById('costSummaryContainer');
            const selectedOptionName = document.getElementById('selectedOptionName');
            const totalCost = document.getElementById('totalCost');

            // Update hidden form fields
            const treatmentCostInput = document.getElementById('treatmentCostInput');
            const selectedOptionCodeInput = document.getElementById('selectedOptionCodeInput');

            if (treatmentCostInput) {
                treatmentCostInput.value = price.toFixed(2);
            }
            if (selectedOptionCodeInput) {
                selectedOptionCodeInput.value = optionCode;
            }

            if (costSummaryContainer && selectedOptionName && totalCost) {
                // Update the displayed information
                selectedOptionName.textContent = optionName;

                if (price > 0) {
                    totalCost.innerHTML = `<i class="fas fa-money-bill-wave ml-1"></i>${price.toFixed(2)} ليرة سورية`;
                    totalCost.className = 'font-bold text-green-600 text-xl';
                } else {
                    totalCost.innerHTML = `<i class="fas fa-gift ml-1"></i>مجاني`;
                    totalCost.className = 'font-bold text-gray-600 text-xl';
                }

                // Show the cost summary container
                costSummaryContainer.style.display = 'block';

                // Add a subtle animation
                costSummaryContainer.style.opacity = '0';
                setTimeout(() => {
                    costSummaryContainer.style.transition = 'opacity 0.3s ease-in-out';
                    costSummaryContainer.style.opacity = '1';
                }, 10);
            }
        }

        function hideCostSummary() {
            const costSummaryContainer = document.getElementById('costSummaryContainer');
            if (costSummaryContainer) {
                costSummaryContainer.style.display = 'none';
            }
        }

        function showTreatmentStages(treatmentType, optionCode, optionId) {
            console.log('عرض مراحل العلاج - optionId:', optionId);
            console.log('نوع العلاج:', treatmentType);

            // مراحل الخيار المحدد فقط؛ لا نستعير مراحل خيار آخر من نفس النوع
            const stages = (optionId && treatmentStages[optionId]) ? treatmentStages[optionId] : [];

            if (stages.length === 0) {
                // مسح أي مراحل معروضة لخيار سابق حتى لا تُحفظ مع هذا العلاج
                window.currentStages = [];
                updateTreatmentStagesInput();

                const option = (treatmentOptions[treatmentType] || []).find(opt => opt.option_code === optionCode);
                document.getElementById('treatmentStages').innerHTML =
                    '<div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center text-gray-600">' +
                    '<i class="fas fa-info-circle text-2xl text-gray-400 mb-2"></i>' +
                    '<p class="font-semibold">لا توجد مراحل محددة لخيار «' + escapeHtml(option ? option.name_ar : optionCode) + '»</p>' +
                    '<p class="text-sm mt-1">يمكنك حفظ العلاج بدون مراحل، أو إضافة مراحل لهذا الخيار من ' +
                    '<a href="treatment_stages_management.php" class="text-blue-600 hover:underline">إدارة مراحل العلاج</a></p>' +
                    '</div>';
                return;
            }

            console.log('عرض المراحل - العدد:', stages.length);

            // إنشاء مدير المراحل التفاعلي
            if (!window.currentStages) {
                window.currentStages = [];
            }

            // تهيئة حالة المراحل
            window.currentStages = stages.map((stage, index) => ({
                ...stage,
                index: index,
                completed: false,
                current: index === 0, // المرحلة الأولى نشطة
                completedDate: null,
                notes: ''
            }));

            renderInteractiveStages();
            console.log('✅ تم عرض المراحل التفاعلية بنجاح');
        }

        function renderInteractiveStages() {
            if (!window.currentStages || window.currentStages.length === 0) {
                document.getElementById('treatmentStages').innerHTML = '<p>لا توجد مراحل</p>';
                return;
            }

            // حساب نسبة التقدم
            const completedCount = window.currentStages.filter(stage => stage.completed).length;
            const progressPercentage = (completedCount / window.currentStages.length) * 100;

            let stagesHTML = `
                <div class="stages-container">
                    <!-- شريط التقدم -->
                    <div class="progress-container" style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <span style="font-weight: bold; color: #333;">تقدم العلاج</span>
                            <span style="color: #666;">${completedCount}/${window.currentStages.length}</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden;">
                            <div style="width: ${progressPercentage}%; height: 100%; background: linear-gradient(45deg, #28a745, #20c997); transition: width 0.3s ease;"></div>
                        </div>
                        <small style="color: #666;">${progressPercentage.toFixed(0)}% مكتمل</small>
                    </div>

                    <!-- قائمة المراحل -->
                    <div class="stages-list">
            `;

            window.currentStages.forEach((stage, index) => {
                const statusClass = stage.completed ? 'completed' : (stage.current ? 'current' : 'upcoming');
                const statusIcon = stage.completed ? '✅' : (stage.current ? '🔄' : '⏸️');
                const statusText = stage.completed ? 'مكتمل' : (stage.current ? 'جاري' : 'في الانتظار');

                const borderColor = stage.completed ? '#28a745' : (stage.current ? '#ffc107' : '#e0e0e0');
                const bgColor = stage.completed ? '#f8fff9' : (stage.current ? '#fffbf0' : '#f8f9fa');

                stagesHTML += `
                    <div class="stage-item" data-stage-index="${index}"
                         style="border: 2px solid ${borderColor}; padding: 15px; margin: 10px 0; border-radius: 8px; background: ${bgColor}; transition: all 0.3s ease;">

                        <!-- رأس المرحلة -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="color: #333; margin: 0; display: flex; align-items: center;">
                                <span style="background: ${stage.completed ? '#28a745' : (stage.current ? '#ffc107' : '#6c757d')};
                                             color: white; padding: 4px 8px; border-radius: 50%; margin-left: 8px; min-width: 30px; text-align: center;">
                                    ${index + 1}
                                </span>
                                ${stage.title_ar}
                            </h4>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 12px; color: #666; background: white; padding: 2px 6px; border-radius: 12px; border: 1px solid #ddd;">
                                    ${statusIcon} ${statusText}
                                </span>
                            </div>
                        </div>

                        <!-- وصف المرحلة -->
                        <p style="color: #555; margin: 8px 0; font-size: 14px; line-height: 1.4;">
                            ${stage.description_ar || ''}
                        </p>

                        <!-- معلومات إضافية -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                            <small style="color: #666; display: flex; align-items: center;">
                                ⏱️ ${stage.duration_ar || 'غير محدد'}
                            </small>

                            <!-- أزرار التحكم -->
                            <div style="display: flex; gap: 8px;">
                                ${stage.current && !stage.completed ? `
                                    <button type="button" onclick="completeStage(${index})"
                                            style="background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        ✓ إكمال المرحلة
                                    </button>
                                ` : ''}

                                ${stage.completed ? `
                                    <button type="button" onclick="uncompleteStage(${index})"
                                            style="background: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        ↶ إلغاء الإكمال
                                    </button>
                                    <small style="color: #28a745; font-size: 11px;">
                                        ✅ تم في: ${stage.completedDate || 'اليوم'}
                                    </small>
                                ` : ''}
                            </div>
                        </div>

                        <!-- ملاحظات المرحلة (داخل المرحلة، اختيارية) -->
                        ${stage.current || stage.completed ? `
                            <div style="margin-top: 10px;">
                                <label style="display: block; font-size: 12px; color: #666; font-weight: bold; margin-bottom: 4px;">ملاحظات المرحلة (اختياري)</label>
                                <textarea rows="2" oninput="updateStageNotes(${index}, this.value)"
                                          placeholder="أضف ملاحظة لهذه المرحلة..."
                                          style="width: 100%; padding: 6px 8px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: white; resize: vertical;">${escapeHtml(stage.notes || '')}</textarea>
                            </div>
                        ` : ''}
                    </div>
                `;
            });

            stagesHTML += '</div></div>';

            // عرض المراحل
            document.getElementById('treatmentStages').innerHTML = stagesHTML;

            // تحديث حقل الإدخال المخفي
            updateTreatmentStagesInput();
        }

        function escapeHtml(text) {
            return String(text).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
        }

        // حفظ ملاحظة المرحلة أثناء الكتابة (بدون إعادة رسم حتى لا يضيع مكان المؤشر)
        function updateStageNotes(stageIndex, value) {
            if (!window.currentStages || !window.currentStages[stageIndex]) return;
            window.currentStages[stageIndex].notes = value;
            updateTreatmentStagesInput();
        }

        function completeStage(stageIndex) {
            if (!window.currentStages || !window.currentStages[stageIndex]) return;

            const stage = window.currentStages[stageIndex];

            // إكمال المرحلة الحالية
            stage.completed = true;
            stage.current = false;
            stage.completedDate = todayISODate();

            // تنشيط المرحلة التالية
            const nextStageIndex = stageIndex + 1;
            if (nextStageIndex < window.currentStages.length) {
                window.currentStages[nextStageIndex].current = true;
            }

            console.log('تم إكمال المرحلة:', stage.title_ar);

            // إعادة رسم المراحل
            renderInteractiveStages();
        }

        function uncompleteStage(stageIndex) {
            if (!window.currentStages || !window.currentStages[stageIndex]) return;

            const stage = window.currentStages[stageIndex];

            // إلغاء إكمال المرحلة
            // الملاحظات المكتوبة تبقى محفوظة عند إلغاء الإكمال
            stage.completed = false;
            stage.current = true;
            stage.completedDate = null;

            // إلغاء تنشيط المراحل التالية
            for (let i = stageIndex + 1; i < window.currentStages.length; i++) {
                window.currentStages[i].current = false;
                window.currentStages[i].completed = false;
                window.currentStages[i].completedDate = null;
            }

            console.log('تم إلغاء إكمال المرحلة:', stage.title_ar);

            // إعادة رسم المراحل
            renderInteractiveStages();
        }

        function updateTreatmentStagesInput() {
            const input = document.getElementById('treatmentStagesInput');
            if (!input) {
                console.warn('treatmentStagesInput element not found');
                return;
            }

            console.log('تحديث مراحل العلاج - بدء التحديث');
            console.log('window.currentStages:', window.currentStages);
            console.log('treatmentStagesManager:', treatmentStagesManager);
            console.log('treatmentStagesManager.stages:', treatmentStagesManager ? treatmentStagesManager.stages : 'لا يوجد');

            // Debug stage titles
            if (treatmentStagesManager && treatmentStagesManager.stages) {
                console.log('Stage titles check:');
                treatmentStagesManager.stages.forEach((stage, index) => {
                    console.log(`Stage ${index}: title="${stage.title}", title_ar="${stage.title_ar}"`);
                });
            }

            // استخدام المراحل التفاعلية الجديدة
            if (window.currentStages && window.currentStages.length > 0) {
                try {
                    input.value = JSON.stringify(window.currentStages);
                    console.log('تم تحديث بيانات المراحل من window.currentStages:', input.value);
                } catch (error) {
                    console.error('خطأ في تحديث بيانات المراحل:', error);
                    input.value = '[]';
                }
            } else if (treatmentStagesManager && treatmentStagesManager.stages) {
                try {
                    const stagesData = treatmentStagesManager.getStagesData ? treatmentStagesManager.getStagesData() : treatmentStagesManager.stages;
                    if (Array.isArray(stagesData) && stagesData.length > 0) {
                        input.value = JSON.stringify(stagesData);
                        console.log('تم تحديث بيانات المراحل من treatmentStagesManager:', input.value);
                    } else {
                        input.value = '[]';
                        console.log('مراحل العلاج فارغة، تم تعيين []');
                    }
                } catch (error) {
                    console.error('Error encoding treatment stages to JSON:', error);
                    input.value = '[]';
                }
            } else {
                input.value = '[]';
                console.log('لا توجد مراحل علاج، تم تعيين []');
            }

            console.log('قيمة treatmentStagesInput النهائية:', input.value);
        }
        
        function validateForm() {
            const action = document.querySelector('input[name="action"]').value;

            // For completion mode, check if all stages are completed
            if (action === 'complete_treatment') {
                if (treatmentStagesManager && treatmentStagesManager.stages && treatmentStagesManager.stages.length > 0) {
                    const incompleteStages = treatmentStagesManager.stages.filter(stage => !stage.completed);
                    if (incompleteStages.length > 0) {
                        const stageNames = incompleteStages.map(stage => stage.title || 'مرحلة غير محددة').join('\n• ');
                        alert('لا يمكن إكمال العلاج حتى يتم إكمال جميع مراحل العلاج التالية:\n\n• ' + stageNames);
                        return false;
                    }
                }
                return true; // For completion, only check stages
            }

            // For new treatment creation
            const patientId = document.getElementById('patientSelect').value;
            const selectedTeethArray = getSelectedTeeth();
            const treatmentType = document.getElementById('selectedTreatmentType').value;

            if (!patientId) {
                alert('يرجى اختيار المريض');
                return false;
            }

            if (selectedTeethArray.length === 0) {
                alert('يرجى تحديد الأسنان المراد علاجها');
                return false;
            }

            if (!treatmentType) {
                alert('يرجى اختيار نوع العلاج');
                return false;
            }

            return true;
        }

        // Load existing treatment data (for completion mode)
        function loadExistingTreatmentData() {
            if (!existingTreatment) return;

            // Load dental data first, then populate the form
            const currentDentalType = document.querySelector('input[name="dental_type"]:checked')?.value || 'adult';
            loadDentalData(currentDentalType).then(() => {
                initializeCustomDentalChart();

                // Pre-fill form fields
                setTimeout(() => {
                    // Treatment date
                    const treatmentDateInput = document.querySelector('input[name="treatment_date"]');
                    if (treatmentDateInput) {
                        treatmentDateInput.value = existingTreatment.treatmentDate;
                    }

                    // Cost
                    const costInput = document.querySelector('input[name="cost"]');
                    if (costInput && existingTreatment.cost) {
                        costInput.value = existingTreatment.cost;
                    }

                    // Next appointment date
                    const nextApptInput = document.querySelector('input[name="next_appointment_date"]');
                    if (nextApptInput && existingTreatment.nextAppointmentDate) {
                        nextApptInput.value = existingTreatment.nextAppointmentDate;
                    }

                    // Symptoms
                    const symptomsInput = document.querySelector('textarea[name="symptoms"]');
                    if (symptomsInput && existingTreatment.symptoms) {
                        symptomsInput.value = existingTreatment.symptoms;
                    }

                    // Diagnosis
                    const diagnosisInput = document.querySelector('textarea[name="diagnosis"]');
                    if (diagnosisInput && existingTreatment.diagnosis) {
                        diagnosisInput.value = existingTreatment.diagnosis;
                    }

                    // Treatment details
                    const treatmentDetailsInput = document.querySelector('textarea[name="treatment_details"]');
                    if (treatmentDetailsInput && existingTreatment.treatmentDetails) {
                        treatmentDetailsInput.value = existingTreatment.treatmentDetails;
                    }

                    // Medications
                    const medicationsInput = document.querySelector('textarea[name="medications"]');
                    if (medicationsInput && existingTreatment.medications) {
                        medicationsInput.value = existingTreatment.medications;
                    }

                    // Notes
                    const notesInput = document.querySelector('textarea[name="notes"]');
                    if (notesInput && existingTreatment.notes) {
                        notesInput.value = existingTreatment.notes;
                    }

                    // Load selected teeth
                    if (existingTreatment.teethNumbers && Array.isArray(existingTreatment.teethNumbers)) {
                        existingTreatment.teethNumbers.forEach(toothNumber => {
                            selectedTeeth.add(toothNumber.toString());
                            const toothElement = document.querySelector(`[data-tooth-number="${toothNumber}"]`);
                            if (toothElement) {
                                toothElement.classList.add('selected');
                            }
                        });
                        updateSelectedTeethList(selectedTeeth);
                        toggleTreatmentSelection(selectedTeeth.size > 0);
                    }

                    // Select treatment type
                    if (existingTreatment.treatmentType) {
                        // Find the treatment type in the available options
                        const treatmentTypeCards = document.querySelectorAll('.treatment-type-card-compact');
                        for (let card of treatmentTypeCards) {
                            const treatmentCode = card.dataset.treatment;
                            if (treatmentCode === existingTreatment.treatmentType ||
                                card.textContent.includes(existingTreatment.treatmentType)) {
                                selectTreatmentType(treatmentCode);

                                // Wait a bit for treatment options to load, then restore selection
                                setTimeout(() => {
                                    restoreTreatmentOption(existingTreatment.treatmentDetails);

                                    // For completion mode, always try to show stages
                                    console.log('في وضع الإكمال - فحص مراحل العلاج');

                                    // Check if any stages exist for this treatment type's options
                                    const hasStagesForTreatment = Object.keys(treatmentStages).some(key => {
                                        return treatmentStages[key] && treatmentStages[key].length > 0;
                                    });

                                    // If we have existing treatment stages data, restore them
                                    if (existingTreatment.treatmentStagesData && Array.isArray(existingTreatment.treatmentStagesData) && existingTreatment.treatmentStagesData.length > 0) {
                                        console.log('استعادة مراحل العلاج الموجودة:', existingTreatment.treatmentStagesData);
                                        // Create a stages manager if needed
                                        if (!treatmentStagesManager) {
                                            treatmentStagesManager = new TreatmentStages('treatmentStages', {
                                                stages: [],
                                                onStageComplete: function(stageIndex, stage) {
                                                    console.log('Stage completed:', stage);
                                                    updateTreatmentStagesInput();
                                                },
                                                onStageUpdate: function(stageIndex, stage) {
                                                    updateTreatmentStagesInput();
                                                }
                                            });
                                        }
                                        restoreTreatmentStages(existingTreatment.treatmentStagesData);
                                    } else if (!hasStagesForTreatment) {
                                        // No template stages and no existing stages - show empty stages section for completion mode
                                        console.log('لا توجد مراحل - إظهار قسم فارغ في وضع الإكمال');
                                        document.getElementById('treatmentStagesColumn').style.display = 'block';
                                        document.getElementById('treatmentDetailsColumn').style.display = 'block';
                                        document.getElementById('submitButtons').style.display = 'flex';

                                        if (!treatmentStagesManager) {
                                            treatmentStagesManager = new TreatmentStages('treatmentStages', {
                                                stages: [],
                                                onStageComplete: function(stageIndex, stage) {
                                                    console.log('Stage completed:', stage);
                                                    updateTreatmentStagesInput();
                                                },
                                                onStageUpdate: function(stageIndex, stage) {
                                                    updateTreatmentStagesInput();
                                                }
                                            });
                                        }

                                        // Show message that no stages are defined for completion mode
                                        document.getElementById('treatmentStages').innerHTML =
                                            '<div style="text-align: center; padding: 20px; color: #666;">' +
                                            '<div class="bg-blue-50 border border-blue-200 rounded-lg p-6">' +
                                            '<i class="fas fa-info-circle text-blue-600 text-2xl mb-3"></i>' +
                                            '<h4 class="text-lg font-semibold text-blue-800 mb-2">لا توجد مراحل محددة لهذا العلاج</h4>' +
                                            '<p class="text-blue-700 mb-4">هذا العلاج لا يحتوي على مراحل محددة مسبقاً.</p>' +
                                            '<p class="text-blue-600 text-sm">يمكنك إكمال العلاج مباشرة باستخدام الزر أدناه.</p>' +
                                            '</div>' +
                                            '</div>';
                                    }
                                }, 200);
                                break;
                            }
                        }
                    }

                }, 500); // Small delay to ensure DOM is ready
            });
        }

        // Function to complete all treatment stages
        function completeAllTreatmentStages() {
            // Always ensure the input has a valid JSON value
            const input = document.getElementById('treatmentStagesInput');

            if (!treatmentStagesManager || !treatmentStagesManager.stages || treatmentStagesManager.stages.length === 0) {
                // No stages to complete, just ensure empty array
                if (input) input.value = '[]';
                console.log('No treatment stages to complete');
                return;
            }

            const currentDate = todayISODate();

            // Mark all stages as completed
            treatmentStagesManager.stages.forEach((stage, index) => {
                if (!stage.completed) {
                    stage.completed = true;
                    stage.completedDate = currentDate;
                    if (!stage.notes || stage.notes.trim() === '') {
                        stage.notes = 'تم إكمال هذه المرحلة عند إكمال العلاج';
                    }
                }
            });

            // Set current stage to the last stage
            treatmentStagesManager.currentStage = treatmentStagesManager.stages.length - 1;

            // Update the hidden input with completed stages data
            updateTreatmentStagesInput();

            // Re-render to show all stages as completed
            treatmentStagesManager.render();

            // Update submit button state
            updateSubmitButtonState();

            console.log('All treatment stages marked as completed');
        }

        // Function to check and update submit button state
        function updateSubmitButtonState() {
            const completeButton = document.getElementById('completeButton');
            const action = document.querySelector('input[name="action"]')?.value;

            if (!completeButton || action !== 'complete_treatment') return;

            if (treatmentStagesManager && treatmentStagesManager.stages && treatmentStagesManager.stages.length > 0) {
                const incompleteStages = treatmentStagesManager.stages.filter(stage => !stage.completed);
                const allStagesCompleted = incompleteStages.length === 0;

                // الزر يبقى متاحاً دائماً؛ عند وجود مراحل متبقية يُطلب تأكيد الإنهاء عند الإرسال
                completeButton.disabled = false;
                completeButton.classList.remove('opacity-50', 'cursor-not-allowed');
                if (allStagesCompleted) {
                    completeButton.classList.remove('bg-yellow-500', 'hover:bg-yellow-600');
                    completeButton.classList.add('bg-green-500', 'hover:bg-green-600');
                    completeButton.innerHTML = '<i class="fas fa-check ml-2"></i>إكمال العلاج';
                } else {
                    completeButton.classList.remove('bg-green-500', 'hover:bg-green-600');
                    completeButton.classList.add('bg-yellow-500', 'hover:bg-yellow-600');
                    completeButton.innerHTML = `<i class="fas fa-flag-checkered ml-2"></i>إنهاء العلاج (متبقٍ ${incompleteStages.length} مرحلة)`;
                }
            }
        }

        // Function to save progress without completing treatment
        function saveProgress() {
            const form = document.getElementById('treatmentForm');
            const actionInput = form.querySelector('input[name="action"]');
            const originalAction = actionInput.value;

            // Update treatment stages input before saving
            updateTreatmentStagesInput();

            // Temporarily change action to save progress
            actionInput.value = 'save_progress';

            // Show loading state
            const saveButton = document.getElementById('saveProgressButton');
            const originalButtonContent = saveButton.innerHTML;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i>جاري الحفظ...';
            saveButton.disabled = true;

            // Submit form
            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Check if save was successful by looking for success message
                if (html.includes('تم حفظ تقدم العلاج بنجاح')) {
                    // Show success message
                    showNotification('تم حفظ تقدم العلاج بنجاح', 'success');
                } else if (html.includes('خطأ في حفظ تقدم العلاج')) {
                    // Extract error message
                    const errorMatch = html.match(/خطأ في حفظ تقدم العلاج: ([^<]+)/);
                    const errorMsg = errorMatch ? errorMatch[1] : 'حدث خطأ في حفظ التقدم';
                    showNotification(errorMsg, 'error');
                } else {
                    showNotification('تم حفظ تقدم العلاج بنجاح', 'success');
                }
            })
            .catch(error => {
                console.error('Error saving progress:', error);
                showNotification('حدث خطأ في حفظ التقدم', 'error');
            })
            .finally(() => {
                // Restore button state
                saveButton.innerHTML = originalButtonContent;
                saveButton.disabled = false;

                // Restore original action
                actionInput.value = originalAction;
            });
        }

        // Function to show notification messages
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700';
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';

            notification.className = `fixed top-4 right-4 ${bgColor} border px-4 py-3 rounded shadow-lg z-50 max-w-md fade-in`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="${icon} ml-2"></i>
                    <span>${message}</span>
                    <button class="mr-2 text-lg font-bold" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;

            document.body.appendChild(notification);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // Function to restore treatment option selection
        function restoreTreatmentOption(treatmentDetails) {
            if (!treatmentDetails) return;

            // Find matching treatment option radio button
            const treatmentOptions = document.querySelectorAll('input[name="treatment_option"]');
            treatmentOptions.forEach(radio => {
                const optionElement = radio.closest('.treatment-option');
                const optionText = optionElement.querySelector('label').textContent;

                // Check if this option matches the stored treatment details
                if (optionText.includes(treatmentDetails) || radio.value === treatmentDetails) {
                    radio.checked = true;
                    optionElement.classList.add('selected');
                }
            });
        }

        // Function to restore treatment stages
        function restoreTreatmentStages(treatmentStagesData) {
            if (!treatmentStagesData || !Array.isArray(treatmentStagesData)) return;

            // Show treatment stages column immediately when we have existing stages
            if (treatmentStagesData.length > 0) {
                console.log('إظهار عمود مراحل العلاج للبيانات الموجودة');
                document.getElementById('treatmentStagesColumn').style.display = 'block';

                // Also show other necessary UI elements for completion mode
                document.getElementById('treatmentDetailsColumn').style.display = 'block';
                document.getElementById('submitButtons').style.display = 'flex';
            }

            // Wait for treatment stages manager to be initialized
            setTimeout(() => {
                if (treatmentStagesManager) {
                    // Normalize the stage data to ensure consistent field names
                    const normalizedStages = treatmentStagesData.map(stage => {
                        return {
                            title: stage.title || stage.title_ar || 'مرحلة غير محددة',
                            description: stage.description || stage.description_ar || '',
                            duration: stage.duration || stage.duration_ar || '',
                            completed: stage.completed || false,
                            completedDate: stage.completedDate || stage.completed_date || null,
                            notes: stage.notes || ''
                        };
                    });

                    console.log('Normalized stages data:', normalizedStages);

                    // Use the normalized stages data
                    treatmentStagesManager.stages = normalizedStages;

                    // Find the current stage (last incomplete stage)
                    let currentStageIndex = 0;
                    for (let i = 0; i < normalizedStages.length; i++) {
                        if (normalizedStages[i].completed) {
                            currentStageIndex = i + 1;
                        } else {
                            break;
                        }
                    }
                    treatmentStagesManager.currentStage = Math.min(currentStageIndex, normalizedStages.length - 1);

                    // Re-render with the restored data
                    treatmentStagesManager.render();

                    // Update submit button state based on stage completion
                    updateSubmitButtonState();

                    console.log('Treatment stages restored:', treatmentStagesData);
                } else {
                    console.warn('Treatment stages manager not initialized yet, retrying...');
                    // Retry after more time
                    setTimeout(() => restoreTreatmentStages(treatmentStagesData), 500);
                }
            }, 300);
        }

        // Follow-up period preview functionality
        document.addEventListener('DOMContentLoaded', function() {
            const followUpRadios = document.querySelectorAll('input[name="follow_up_period"]');
            const followUpPreview = document.getElementById('followUpPreview');
            const followUpPreviewText = document.getElementById('followUpPreviewText');

            function updateFollowUpPreview() {
                const selectedPeriod = document.querySelector('input[name="follow_up_period"]:checked');
                if (selectedPeriod && selectedPeriod.value) {
                    const months = parseInt(selectedPeriod.value);
                    const currentDate = new Date();
                    const followUpDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + months, currentDate.getDate());

                    const options = {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        weekday: 'long'
                    };

                    const formattedDate = followUpDate.toLocaleDateString('ar-SA', options);

                    followUpPreviewText.textContent = `سيتم جدولة متابعة تلقائية في ${formattedDate} (بعد ${months} ${months === 3 ? 'أشهر' : months === 6 ? 'أشهر' : 'أشهر'})`;
                    followUpPreview.style.display = 'block';
                } else {
                    followUpPreview.style.display = 'none';
                }
            }

            // Add event listeners to all follow-up radio buttons
            followUpRadios.forEach(radio => {
                radio.addEventListener('change', updateFollowUpPreview);
            });

            // Initialize preview
            updateFollowUpPreview();
        });

    </script>
</body>
</html>