<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin(['nurse', 'doctor']);

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

// معالجة الإجراءات
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// إضافة مريض جديد
if ($_POST && $action === 'add') {
    try {
        $pdo->beginTransaction();

        // Check if date_of_birth column exists
        $has_birth_date_column = true;
        try {
            $pdo->query("SELECT date_of_birth FROM patients LIMIT 1");
        } catch (PDOException $e) {
            $has_birth_date_column = false;
        }

        // Check if next_birthday_followup column exists
        $has_birthday_followup_column = true;
        try {
            $pdo->query("SELECT next_birthday_followup FROM patients LIMIT 1");
        } catch (PDOException $e) {
            $has_birthday_followup_column = false;
        }

        if ($has_birth_date_column && $has_birthday_followup_column) {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, date_of_birth, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, next_birthday_followup, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        } elseif ($has_birth_date_column) {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, date_of_birth, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO patients (name, phone, age, gender, address, email, emergency_contact,
                                    medical_history, allergies, blood_type, registration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
        }

        // Handle date of birth and calculate next birthday followup
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $next_birthday_followup = null;

        if ($date_of_birth && $has_birth_date_column) {
            // Calculate next birthday
            $birth_date = new DateTime($date_of_birth);
            $current_year = date('Y');
            $next_birthday = new DateTime($current_year . '-' . $birth_date->format('m-d'));

            // If birthday has passed this year, set to next year
            if ($next_birthday < new DateTime()) {
                $next_birthday->modify('+1 year');
            }
            $next_birthday_followup = $next_birthday->format('Y-m-d');
        }

        // Handle optional age field
        $age = !empty($_POST['age']) ? $_POST['age'] : null;

        if ($has_birth_date_column && $has_birthday_followup_column) {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $date_of_birth,
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? '',
                $next_birthday_followup
            ]);
        } elseif ($has_birth_date_column) {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $date_of_birth,
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? ''
            ]);
        } else {
            $result = $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $age,
                $_POST['gender'],
                $_POST['address'] ?? '',
                $_POST['email'] ?? '',
                $_POST['emergency_contact'] ?? '',
                $_POST['medical_history'] ?? '',
                $_POST['allergies'] ?? '',
                $_POST['blood_type'] ?? ''
            ]);
        }

        if ($result) {
            $patient_id = $pdo->lastInsertId();

            // Create birthday follow-up if date of birth is provided
            if ($date_of_birth && $next_birthday_followup) {
                createBirthdayFollowup($pdo, $patient_id, $next_birthday_followup);
            }

            $pdo->commit();

            // الطبيب ينتقل مباشرة إلى ملف المريض الجديد (لبدء علاج مثلاً)
            if ($_SESSION['user_role'] === 'doctor') {
                $_SESSION['patient_profile_success'] = "تم إضافة المريض بنجاح";
                header('Location: ../doctor/patient_profile.php?id=' . (int)$patient_id);
                exit;
            }

            $success_message = "تم إضافة المريض بنجاح";
            $action = ''; // إخفاء النموذج
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_message = "خطأ في إضافة المريض: " . $e->getMessage();
    }
}

// Function to create birthday follow-up
function createBirthdayFollowup($pdo, $patient_id, $followup_date) {
    try {
        // Check if follow_ups table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'follow_ups'");
        if ($stmt->rowCount() == 0) {
            return; // Table doesn't exist yet
        }

        $stmt = $pdo->prepare("
            INSERT INTO follow_ups (patient_id, follow_up_type, follow_up_date, follow_up_reason, priority, created_by)
            VALUES (?, 'birthday', ?, 'متابعة عيد الميلاد السنوية - فحص وقائي', 'normal', ?)
        ");
        $stmt->execute([
            $patient_id,
            $followup_date,
            $_SESSION['user_id']
        ]);
    } catch (PDOException $e) {
        // Log error but don't fail the patient creation
        error_log("Failed to create birthday follow-up: " . $e->getMessage());
    }
}

// البحث والفلترة
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// بناء الاستعلام
$where_conditions = ["p.status != 'deleted'"];
$params = [];

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'new') {
    $where_conditions[] = "DATE(p.registration_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter === 'active') {
    $where_conditions[] = "p.last_visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filter === 'followup') {
    $where_conditions[] = "p.last_visit_date <= DATE_SUB(NOW(), INTERVAL 90 DAY)";
}

$where_clause = implode(' AND ', $where_conditions);

// جلب المرضى مع الترقيم
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM patients p WHERE $where_clause");
    $count_stmt->execute($params);
    $total_patients = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
               (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) as last_visit_date,
               (SELECT COUNT(*) FROM appointments WHERE patient_id = p.id) as total_visits
        FROM patients p 
        WHERE $where_clause
        ORDER BY p.registration_date DESC 
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_patients / $per_page);
    
} catch (PDOException $e) {
    $error_message = "خطأ في قاعدة البيانات: " . $e->getMessage();
    $patients = [];
    $total_patients = 0;
    $total_pages = 0;
}

// إحصائيات المرضى
try {
    // المرضى الجدد هذا الأسبوع
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(registration_date) >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $new_patients_count = $stmt->fetchColumn();
    
    // المرضى النشطين (زاروا خلال الشهر الماضي)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM patients p 
        WHERE EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND DATE(a.appointment_date) >= DATE_SUB(NOW(), INTERVAL 30 DAY))
    ");
    $active_patients_count = $stmt->fetchColumn();
    
    // المرضى بحاجة متابعة (لم يزوروا منذ 3 شهور)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM patients p 
        WHERE (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) <= DATE_SUB(NOW(), INTERVAL 90 DAY)
        OR (SELECT MAX(appointment_date) FROM appointments WHERE patient_id = p.id) IS NULL
    ");
    $followup_patients_count = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $new_patients_count = $active_patients_count = $followup_patients_count = 0;
}

// Set page variables for header
$pageTitle = 'إدارة المرضى';
$pageIcon = 'fas fa-users';
$pageSubtitle = 'إجمالي المرضى: ' . number_format($total_patients ?? 0);
$currentPage = 'patients';

// إغلاق نموذج الإضافة: الطبيب يعود إلى قائمة مرضاه
$form_close_url = $_SESSION['user_role'] === 'doctor' ? '../doctor/patients.php' : 'patients.php';

// صفحة إضافة مريض (بهوية EDSM مثل صفحة حجز موعد)
if ($action === 'add') {
    $pageTitle = 'إضافة مريض جديد';
    $pageIcon = 'fas fa-user-plus';
    $breadcrumbs = [
        ['title' => 'المرضى', 'url' => $form_close_url],
        ['title' => 'إضافة مريض جديد'],
    ];

    // رابط ملف المريض حسب الدور
    $profile_url = $_SESSION['user_role'] === 'doctor' ? '../doctor/patient_profile.php?id=' : 'patient_details.php?id=';

    try {
        $latest_patients = $pdo->query("
            SELECT id, name, phone, created_at, registration_date
            FROM patients WHERE status = 'active'
            ORDER BY created_at DESC, id DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $latest_patients = [];
    }
}

// إعادة تعبئة النموذج بعد خطأ في الحفظ
$old = fn($key) => htmlspecialchars($_POST[$key] ?? '');
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .hover-scale:hover { transform: scale(1.02); transition: transform 0.2s; }
        .new-patient { border-right: 4px solid #3b82f6; }
        .urgent-patient { border-right: 4px solid #f59e0b; }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'includes/role_header.php'; ?>

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

        <?php if ($action === 'add'): ?>
        <!-- ================= إضافة مريض جديد (هوية EDSM) ================= -->
        <form method="POST" id="patientForm">
            <input type="hidden" name="action" value="add">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                <!-- النموذج -->
                <div class="edsm-card is-flush lg:col-span-2 fade-in">
                    <section class="edsm-form-sec">
                        <div class="edsm-form-sec-head">
                            <span class="edsm-avatar-soft"><i class="fas fa-id-card"></i></span>
                            <div>
                                <h3>المعلومات الأساسية</h3>
                                <p>الحقول المعلّمة بـ <span class="edsm-req">*</span> مطلوبة</p>
                            </div>
                        </div>

                        <div class="edsm-form-grid">
                            <div class="is-wide">
                                <label for="pfName" class="edsm-label">الاسم الكامل <span class="edsm-req">*</span></label>
                                <input type="text" name="name" id="pfName" required maxlength="100" autocomplete="off"
                                       class="edsm-field" value="<?= $old('name') ?>" placeholder="الاسم الثلاثي للمريض">
                                <p class="edsm-field-error" id="pfNameError" hidden></p>
                            </div>

                            <div>
                                <label for="pfPhone" class="edsm-label">رقم الهاتف <span class="edsm-req">*</span></label>
                                <input type="tel" name="phone" id="pfPhone" required pattern="09[0-9]{8}" inputmode="numeric" autocomplete="off"
                                       class="edsm-field edsm-ltr edsm-num" value="<?= $old('phone') ?>" placeholder="09xxxxxxxx">
                                <p class="edsm-field-error" id="pfPhoneError" hidden></p>
                            </div>

                            <div>
                                <span class="edsm-label" id="pfGenderLabel">الجنس <span class="edsm-req">*</span></span>
                                <div class="edsm-seg" id="pfGender" role="radiogroup" aria-labelledby="pfGenderLabel">
                                    <label>
                                        <input type="radio" name="gender" value="male" required <?= ($_POST['gender'] ?? '') === 'male' ? 'checked' : '' ?>>
                                        <span><i class="fas fa-mars"></i> ذكر</span>
                                    </label>
                                    <label>
                                        <input type="radio" name="gender" value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'checked' : '' ?>>
                                        <span><i class="fas fa-venus"></i> أنثى</span>
                                    </label>
                                </div>
                                <p class="edsm-field-error" id="pfGenderError" hidden></p>
                            </div>

                            <div>
                                <label for="dateOfBirth" class="edsm-label">تاريخ الميلاد</label>
                                <input type="date" name="date_of_birth" id="dateOfBirth" max="<?= date('Y-m-d') ?>"
                                       class="edsm-field edsm-num" value="<?= $old('date_of_birth') ?>">
                                <p class="edsm-field-error" id="dateOfBirthError" hidden></p>
                            </div>

                            <div>
                                <label for="ageInput" class="edsm-label">العمر</label>
                                <input type="number" name="age" id="ageInput" min="1" max="150" inputmode="numeric"
                                       class="edsm-field edsm-num" value="<?= $old('age') ?>" placeholder="بالسنوات (اختياري)">
                                <p class="edsm-field-error" id="ageInputError" hidden></p>
                                <p class="edsm-hint">يُحسب تلقائياً عند إدخال تاريخ الميلاد</p>
                            </div>
                        </div>
                    </section>

                    <section class="edsm-form-sec">
                        <div class="edsm-form-sec-head">
                            <span class="edsm-avatar-soft is-teal"><i class="fas fa-address-book"></i></span>
                            <div>
                                <h3>معلومات التواصل</h3>
                                <p>للوصول إلى المريض أو أحد أقاربه عند الحاجة</p>
                            </div>
                        </div>

                        <div class="edsm-form-grid">
                            <div>
                                <label for="pfEmergency" class="edsm-label">هاتف الطوارئ</label>
                                <input type="tel" name="emergency_contact" id="pfEmergency" inputmode="numeric" autocomplete="off"
                                       class="edsm-field edsm-ltr edsm-num" value="<?= $old('emergency_contact') ?>" placeholder="رقم هاتف أحد الأقارب">
                            </div>

                            <div>
                                <label for="pfAddress" class="edsm-label">العنوان</label>
                                <input type="text" name="address" id="pfAddress" class="edsm-field"
                                       value="<?= $old('address') ?>" placeholder="المدينة، الحي، الشارع...">
                            </div>
                        </div>
                    </section>

                    <section class="edsm-form-sec">
                        <div class="edsm-form-sec-head">
                            <span class="edsm-avatar-soft is-red"><i class="fas fa-notes-medical"></i></span>
                            <div>
                                <h3>المعلومات الطبية</h3>
                                <p>تظهر للطبيب في ملف المريض قبل بدء أي علاج</p>
                            </div>
                        </div>

                        <label for="pfHistory" class="edsm-label">التاريخ المرضي <span class="font-normal text-gray-500">(أمراض مزمنة - الأدوية - الحساسية - عمليات جراحية سابقة)</span></label>
                        <div class="edsm-quick-dates mb-2" id="pfHistoryChips">
                            <span>إضافة سريعة:</span>
                            <?php foreach (['سكري', 'ارتفاع ضغط الدم', 'أمراض القلب', 'ربو', 'مميّعات الدم', 'حساسية البنسلين', 'حمل'] as $condition): ?>
                                <button type="button" data-term="<?= $condition ?>"><?= $condition ?></button>
                            <?php endforeach; ?>
                        </div>
                        <textarea name="medical_history" id="pfHistory" rows="4" class="edsm-field"
                                  placeholder="اكتب أي أمراض مزمنة أو أدوية أو حساسية أو عمليات سابقة... اتركه فارغاً إن لم يوجد"><?= $old('medical_history') ?></textarea>
                    </section>

                    <div class="edsm-form-foot">
                        <button type="submit" class="edsm-btn edsm-btn-navy edsm-btn-lg">
                            <i class="fas fa-save"></i>
                            <?= $_SESSION['user_role'] === 'doctor' ? 'حفظ وفتح ملف المريض' : 'حفظ المريض' ?>
                        </button>
                        <a href="<?= htmlspecialchars($form_close_url) ?>" class="edsm-btn edsm-btn-sky edsm-btn-lg">إلغاء</a>
                    </div>
                </div>

                <!-- الشريط الجانبي: معاينة الملف + أحدث المرضى -->
                <aside class="edsm-side-sticky space-y-6">
                    <section class="edsm-card edsm-pinfo edsm-pf-preview hidden lg:block fade-in" aria-label="معاينة ملف المريض">
                        <span class="edsm-chip edsm-pf-preview-tag"><i class="far fa-eye"></i> معاينة الملف</span>
                        <div class="edsm-pinfo-avatar edsm-pf-avatar">
                            <span data-avatar="" class="edsm-pf-avatar-empty"><i class="fas fa-user"></i></span>
                            <span data-avatar="male" hidden><?= patientAvatarSvg('male', 'pfAvatarMale') ?></span>
                            <span data-avatar="female" hidden><?= patientAvatarSvg('female', 'pfAvatarFemale') ?></span>
                        </div>
                        <h2 class="edsm-pinfo-name" id="pvName">اسم المريض</h2>

                        <dl class="edsm-pinfo-list">
                            <div><dt>العمر:</dt><dd id="pvAge">—</dd></div>
                            <div><dt>تاريخ الولادة:</dt><dd id="pvDob" class="edsm-num">—</dd></div>
                            <div><dt>الجنس:</dt><dd id="pvGender">—</dd></div>
                        </dl>

                        <ul class="edsm-pinfo-contact">
                            <li><i class="fas fa-phone-alt"></i><span id="pvPhone" class="edsm-num" dir="ltr">—</span></li>
                            <li class="is-alert" id="pvEmergencyRow" hidden><i class="fas fa-phone-volume"></i><span>طوارئ: <span id="pvEmergency" class="edsm-num" dir="ltr"></span></span></li>
                            <li id="pvAddressRow" hidden><i class="fas fa-map-marker-alt"></i><span id="pvAddress"></span></li>
                            <li class="is-alert" id="pvHistoryRow" hidden><i class="fas fa-notes-medical"></i><span id="pvHistory"></span></li>
                        </ul>

                        <div class="edsm-booking-summary mt-4" id="pvBirthday">
                            <i class="fas fa-birthday-cake"></i>
                            <span>أدخل تاريخ الميلاد لإنشاء متابعة سنوية للفحص الوقائي</span>
                        </div>
                    </section>

                    <section class="edsm-card fade-in">
                        <div class="edsm-card-head" style="margin-bottom: 8px;">
                            <h3 class="edsm-card-title" style="font-size: 17px;"><i class="fas fa-history"></i> أحدث المرضى المضافين</h3>
                            <a href="<?= htmlspecialchars($form_close_url) ?>" class="edsm-link text-sm">عرض الكل <i class="fas fa-chevron-left text-xs"></i></a>
                        </div>
                        <?php if (empty($latest_patients)): ?>
                            <p class="text-sm text-gray-500 py-3 text-center">لا يوجد مرضى بعد</p>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($latest_patients as $latest): ?>
                                    <a href="<?= $profile_url . (int)$latest['id'] ?>" class="edsm-upcoming">
                                        <span class="flex-1 min-w-0">
                                            <span class="edsm-row-title block truncate"><?= htmlspecialchars($latest['name']) ?></span>
                                            <span class="edsm-row-meta block edsm-num">
                                                <span dir="ltr"><?= htmlspecialchars($latest['phone']) ?></span> ·
                                                <?= $latest['created_at'] ? date('d/m/Y H:i', strtotime($latest['created_at'])) : date('d/m/Y', strtotime($latest['registration_date'])) ?>
                                            </span>
                                        </span>
                                        <span class="edsm-avatar-soft is-violet"><i class="fas fa-tooth"></i></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </aside>
            </div>
        </form>

        <?php else: ?>
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 fade-in">
            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-blue-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى جدد</p>
                        <p class="text-3xl font-bold text-blue-600"><?= $new_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">هذا الأسبوع</p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=new" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        عرض المرضى الجدد <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-green-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">مرضى نشطين</p>
                        <p class="text-3xl font-bold text-green-600"><?= $active_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">زاروا هذا الشهر</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i class="fas fa-heartbeat text-green-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=active" class="text-green-600 hover:text-green-800 text-sm font-medium">
                        عرض المرضى النشطين <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-lg p-6 border-r-4 border-yellow-500 hover-scale">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">يحتاجون متابعة</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $followup_patients_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">لم يزوروا منذ 3 شهور</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="?filter=followup" class="text-yellow-600 hover:text-yellow-800 text-sm font-medium">
                        عرض المرضى <i class="fas fa-arrow-left mr-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Search and Controls -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8 fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex-1 flex flex-col sm:flex-row gap-4">
                    <!-- Search Form -->
                    <form method="GET" class="flex-1">
                        <div class="relative">
                            <input type="text" 
                                   name="search" 
                                   value="<?= htmlspecialchars($search) ?>"
                                   placeholder="البحث بالاسم أو رقم الهاتف..." 
                                   class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <?php if ($filter): ?>
                                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex gap-4">
                    <a href="?action=add" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition flex items-center">
                        <i class="fas fa-user-plus ml-2"></i>
                        إضافة مريض جديد
                    </a>
 
                </div>
            </div>
        </div>

        <!-- Patients List -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list ml-2"></i>
                        قائمة المرضى
                    </h3>
                    <span class="text-sm text-gray-600">
                        عرض <?= count($patients) ?> من أصل <?= number_format($total_patients) ?> مريض
                    </span>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($patients)): ?>
                    <div class="text-center py-16">
                        <i class="fas fa-users text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد مرضى</h3>
                        <p class="text-gray-600 mb-6">
                            <?php if ($search || $filter): ?>
                                لم يتم العثور على مرضى تطابق معايير البحث.
                            <?php else: ?>
                                لم يتم إضافة أي مرضى بعد.
                            <?php endif; ?>
                        </p>
                        <a href="?action=add" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-user-plus ml-1"></i>
                            إضافة أول مريض
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                        $is_new = (strtotime($patient['registration_date']) > strtotime('-7 days'));
                        $needs_followup = !$patient['last_visit_date'] || strtotime($patient['last_visit_date']) < strtotime('-90 days');
                        $card_class = $is_new ? 'new-patient' : ($needs_followup ? 'urgent-patient' : '');
                        ?>
                        <div class="patient-card p-6 hover:bg-gray-50 <?= $card_class ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="bg-green-100 p-2 rounded-full ml-3">
                                            <i class="fas fa-user text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-900">
                                                <?= htmlspecialchars($patient['name']) ?>
                                                <?php if ($is_new): ?>
                                                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-2">جديد</span>
                                                <?php endif; ?>
                                                <?php if ($needs_followup): ?>
                                                    <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full mr-2">يحتاج متابعة</span>
                                                <?php endif; ?>
                                            </h4>
                                            <div class="flex items-center text-sm text-gray-600 mt-1">
                                                <i class="fas fa-phone ml-1"></i>
                                                <a href="tel:<?= $patient['phone'] ?>" class="text-green-600 hover:text-green-800 ml-4">
                                                    <?= $patient['phone'] ?>
                                                </a>
                                                <i class="fas fa-birthday-cake ml-1"></i>
                                                <span class="ml-4"><?= $patient['age'] ?> سنة</span>
                                                <i class="fas fa-<?= $patient['gender'] === 'male' ? 'mars' : 'venus' ?> ml-1"></i>
                                                <span><?= $patient['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 text-sm">
                                        <div class="text-gray-600">
                                            <i class="fas fa-calendar-plus text-blue-500 ml-1"></i>
                                            <strong>تاريخ التسجيل:</strong>
                                            <?= date('d/m/Y', strtotime($patient['registration_date'])) ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-calendar-check text-green-500 ml-1"></i>
                                            <strong>آخر زيارة:</strong>
                                            <?= $patient['last_visit_date'] ? date('d/m/Y', strtotime($patient['last_visit_date'])) : 'لم يزر بعد' ?>
                                        </div>
                                        <div class="text-gray-600">
                                            <i class="fas fa-chart-line text-purple-500 ml-1"></i>
                                            <strong>عدد الزيارات:</strong>
                                            <?= $patient['total_visits'] ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($patient['medical_history'] || $patient['allergies']): ?>
                                        <div class="mt-4 p-3 bg-red-50 border-r-4 border-red-300 rounded">
                                            <div class="flex items-start">
                                                <i class="fas fa-exclamation-triangle text-red-600 mt-0.5 ml-2"></i>
                                                <div class="text-sm">
                                                    <?php if ($patient['medical_history']): ?>
                                                        <div class="text-red-800">
                                                            <strong>التاريخ المرضي (أمراض مزمنة - الأدوية - الحساسية - عمليات جراحية سابقة):</strong>
                                                            <?= htmlspecialchars($patient['medical_history']) ?>
                                                        </div>
                                                    <?php endif; ?>
 
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 mr-4">
                                    <a href="patient_details.php?id=<?= $patient['id'] ?>" 
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-eye ml-1"></i>
                                        عرض التفاصيل
                                    </a>
                                    
                                    <a href="appointments.php?action=add&patient_id=<?= $patient['id'] ?>" 
                                       class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-calendar-plus ml-1"></i>
                                        حجز موعد
                                    </a>
                                    
                                    <a href="waiting_list.php?action=add&patient_id=<?= $patient['id'] ?>" 
                                       class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-clock ml-1"></i>
                                        إضافة للانتظار
                                    </a>
                                    
                                    <a href="?action=edit&id=<?= $patient['id'] ?>" 
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm transition flex items-center">
                                        <i class="fas fa-edit ml-1"></i>
                                        تعديل
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center fade-in">
                <nav class="flex items-center space-x-2 space-x-reverse">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                           class="bg-white border border-gray-300 text-gray-500 hover:bg-gray-50 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="bg-green-500 text-white px-4 py-2 rounded-lg font-medium">
                                <?= $i ?>
                            </span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                               class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg transition">
                                <?= $i ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
                           class="bg-white border border-gray-300 text-gray-500 hover:bg-gray-50 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
        <?php endif; /* $action === 'add' */ ?>
    </div>

    <script>
        // أرقام الهاتف: أرقام فقط وبحد أقصى 10
        document.querySelectorAll('input[type="tel"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            });
        });
    </script>

    <?php if ($action === 'add'): ?>
    <script>
        (function () {
            const form = document.getElementById('patientForm');
            const nameInput = document.getElementById('pfName');
            const phoneInput = document.getElementById('pfPhone');
            const dobInput = document.getElementById('dateOfBirth');
            const ageInput = document.getElementById('ageInput');
            const emergencyInput = document.getElementById('pfEmergency');
            const addressInput = document.getElementById('pfAddress');
            const historyInput = document.getElementById('pfHistory');
            const genderGroup = document.getElementById('pfGender');
            const genderInputs = form.querySelectorAll('input[name="gender"]');

            // التحقق يتم هنا برسائل تحت الحقول؛ بدون JavaScript يبقى تحقق المتصفح
            form.noValidate = true;

            function formatDate(date) {
                try {
                    return date.toLocaleDateString('ar-u-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' });
                } catch (e) {
                    return date.toISOString().slice(0, 10);
                }
            }

            function parseDate(value) {
                const [y, m, d] = value.split('-').map(Number);
                return new Date(y, m - 1, d);
            }

            function ageLabel(years) {
                if (years === 1) return 'عام واحد';
                if (years === 2) return 'عامان';
                if (years >= 3 && years <= 10) return years + ' أعوام';
                return years + ' عاماً';
            }

            function ageFromBirthDate(birthDate) {
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const monthDiff = today.getMonth() - birthDate.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                return age;
            }

            function selectedGender() {
                const checked = form.querySelector('input[name="gender"]:checked');
                return checked ? checked.value : '';
            }

            // ---------- العمر ⇄ تاريخ الميلاد ----------
            dobInput.addEventListener('change', function () {
                delete this.dataset.auto;
                if (this.value) {
                    const age = ageFromBirthDate(parseDate(this.value));
                    if (age >= 0 && age <= 150) ageInput.value = age || '';
                }
                clearError(this);
                updatePreview();
            });

            // عند إدخال العمر فقط يُقدَّر تاريخ الميلاد (1 كانون الثاني) ويُحدَّث مع كل تعديل
            ageInput.addEventListener('input', function () {
                if (dobInput.value && dobInput.dataset.auto !== '1') {
                    updatePreview();
                    return;
                }
                const age = parseInt(this.value, 10);
                if (age > 0 && age <= 150) {
                    dobInput.value = (new Date().getFullYear() - age) + '-01-01';
                    dobInput.dataset.auto = '1';
                } else if (dobInput.dataset.auto === '1') {
                    dobInput.value = '';
                    delete dobInput.dataset.auto;
                }
                clearError(this);
                updatePreview();
            });

            // ---------- إضافة سريعة للتاريخ المرضي ----------
            const chips = document.querySelectorAll('#pfHistoryChips button');

            function syncChips() {
                chips.forEach(chip => chip.classList.toggle('is-on', historyInput.value.includes(chip.dataset.term)));
            }

            chips.forEach(chip => {
                chip.addEventListener('click', function () {
                    const term = this.dataset.term;
                    const current = historyInput.value.trim();
                    if (!current.includes(term)) {
                        historyInput.value = current ? current.replace(/[،,\s]+$/, '') + '، ' + term : term;
                    }
                    historyInput.focus();
                    syncChips();
                    updatePreview();
                });
            });

            // ---------- معاينة الملف ----------
            const avatars = document.querySelectorAll('.edsm-pf-avatar [data-avatar]');

            function setText(id, value, fallback) {
                document.getElementById(id).textContent = value || fallback;
            }

            function setRow(rowId, textId, value) {
                document.getElementById(rowId).hidden = !value;
                document.getElementById(textId).textContent = value;
            }

            function updatePreview() {
                const gender = selectedGender();
                const age = parseInt(ageInput.value, 10);

                setText('pvName', nameInput.value.trim(), 'اسم المريض');
                document.getElementById('pvName').classList.toggle('is-empty', !nameInput.value.trim());
                setText('pvPhone', phoneInput.value.trim(), '—');
                setText('pvGender', gender === 'male' ? 'ذكر' : gender === 'female' ? 'أنثى' : '', '—');
                setText('pvAge', age > 0 ? ageLabel(age) : '', '—');
                setText('pvDob', dobInput.value && dobInput.dataset.auto !== '1' ? formatDate(parseDate(dobInput.value)) : '', '—');
                setRow('pvEmergencyRow', 'pvEmergency', emergencyInput.value.trim());
                setRow('pvAddressRow', 'pvAddress', addressInput.value.trim());
                setRow('pvHistoryRow', 'pvHistory', historyInput.value.trim());

                avatars.forEach(el => { el.hidden = el.dataset.avatar !== gender; });

                // متابعة عيد الميلاد: نفس حساب الخادم (هذا العام، أو العام القادم إن مرّ)
                const box = document.getElementById('pvBirthday');
                const text = box.querySelector('span');
                box.classList.remove('is-ready', 'is-estimated');
                if (!dobInput.value) {
                    text.textContent = 'أدخل تاريخ الميلاد لإنشاء متابعة سنوية للفحص الوقائي';
                    return;
                }
                const birth = parseDate(dobInput.value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const next = new Date(today.getFullYear(), birth.getMonth(), birth.getDate());
                if (next <= today) next.setFullYear(next.getFullYear() + 1);

                if (dobInput.dataset.auto === '1') {
                    box.classList.add('is-estimated');
                    text.textContent = 'تاريخ الميلاد مُقدَّر من العمر، فستكون المتابعة في ' + formatDate(next) + '. أدخل التاريخ الصحيح لتكون في موعدها.';
                } else {
                    box.classList.add('is-ready');
                    text.textContent = 'ستُنشأ متابعة عيد الميلاد السنوية في ' + formatDate(next);
                }
            }

            [nameInput, phoneInput, emergencyInput, addressInput].forEach(input => {
                input.addEventListener('input', () => { clearError(input); updatePreview(); });
            });
            historyInput.addEventListener('input', () => { syncChips(); updatePreview(); });
            genderInputs.forEach(input => input.addEventListener('change', () => { clearError(genderGroup); updatePreview(); }));

            // ---------- التحقق ----------
            function errorBox(el) {
                return document.getElementById((el === genderGroup ? 'pfGender' : el.id) + 'Error');
            }

            function setError(el, message) {
                el.classList.add('is-invalid');
                const box = errorBox(el);
                box.textContent = message;
                box.hidden = false;
            }

            function clearError(el) {
                el.classList.remove('is-invalid');
                const box = errorBox(el);
                if (box) box.hidden = true;
            }

            form.addEventListener('submit', function (e) {
                const invalid = [];
                const phone = phoneInput.value.trim();
                const age = ageInput.value;

                if (!nameInput.value.trim()) {
                    setError(nameInput, 'يرجى إدخال اسم المريض');
                    invalid.push(nameInput);
                }
                if (!phone) {
                    setError(phoneInput, 'يرجى إدخال رقم الهاتف');
                    invalid.push(phoneInput);
                } else if (!/^09[0-9]{8}$/.test(phone)) {
                    setError(phoneInput, 'رقم الهاتف يجب أن يبدأ بـ 09 ويتكون من 10 أرقام');
                    invalid.push(phoneInput);
                }
                if (!selectedGender()) {
                    setError(genderGroup, 'يرجى اختيار الجنس');
                    invalid.push(genderInputs[0]);
                }
                if (dobInput.value && parseDate(dobInput.value) > new Date()) {
                    setError(dobInput, 'تاريخ الميلاد لا يمكن أن يكون في المستقبل');
                    invalid.push(dobInput);
                }
                if (age && (age < 1 || age > 150)) {
                    setError(ageInput, 'العمر يجب أن يكون بين 1 و 150 سنة');
                    invalid.push(ageInput);
                }

                if (invalid.length) {
                    e.preventDefault();
                    invalid[0].focus();
                    return;
                }

                const submit = form.querySelector('button[type="submit"]');
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الحفظ...';
            });

            syncChips();
            updatePreview();
            if (!nameInput.value) nameInput.focus();
        })();
    </script>
    <?php else: ?>
    <script>
        // البحث التلقائي أثناء الكتابة
        const searchInput = document.querySelector('input[name="search"]');
        let searchTimeout;

        searchInput?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 1000);
        });

        searchInput?.focus();
    </script>
    <?php endif; ?>
</body>
</html>