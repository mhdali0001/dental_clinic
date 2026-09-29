<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$patient_id = (int)($_GET['id'] ?? 0);
if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

$blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$errors = [];
$error_message = '';

if (empty($_SESSION['patient_edit_token'])) {
    $_SESSION['patient_edit_token'] = bin2hex(random_bytes(32));
}

// جلب بيانات المريض
try {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patient = false;
    $error_message = "خطأ في جلب بيانات المريض: " . $e->getMessage();
}

if (!$patient && !$error_message) {
    header('Location: patients.php');
    exit;
}

// القيم المعروضة في النموذج (تُستبدل بالقيم المرسلة عند وجود أخطاء)
$form = $patient ?: [];

// حساب تاريخ عيد الميلاد القادم
function nextBirthdayDate($date_of_birth) {
    $birth = new DateTime($date_of_birth);
    $today = new DateTime('today');
    $year = (int)$today->format('Y');
    $month = (int)$birth->format('m');
    $day = (int)$birth->format('d');
    // مواليد 29 فبراير في السنوات غير الكبيسة
    if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
        $day = 28;
    }
    $next = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    if ($next < $today) {
        $next->modify('+1 year');
    }
    return $next->format('Y-m-d');
}

// حفظ التعديلات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient) {
    if (!hash_equals($_SESSION['patient_edit_token'], $_POST['token'] ?? '')) {
        $errors['general'] = 'انتهت صلاحية النموذج، يرجى إعادة المحاولة.';
    }

    $form = array_merge($patient, [
        'name'              => trim($_POST['name'] ?? ''),
        'phone'             => trim($_POST['phone'] ?? ''),
        'gender'            => $_POST['gender'] ?? '',
        'date_of_birth'     => trim($_POST['date_of_birth'] ?? ''),
        'age'               => trim($_POST['age'] ?? ''),
        'status'            => $_POST['status'] ?? $patient['status'],
        'email'             => trim($_POST['email'] ?? ''),
        'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
        'address'           => trim($_POST['address'] ?? ''),
        'blood_type'        => $_POST['blood_type'] ?? '',
        'medical_history'   => trim($_POST['medical_history'] ?? ''),
        'allergies'         => trim($_POST['allergies'] ?? ''),
    ]);

    if ($form['name'] === '') {
        $errors['name'] = 'الاسم مطلوب';
    } elseif (mb_strlen($form['name']) > 100) {
        $errors['name'] = 'الاسم طويل جداً (100 حرف كحد أقصى)';
    }

    if ($form['phone'] === '') {
        $errors['phone'] = 'رقم الهاتف مطلوب';
    } elseif (!preg_match('/^\+?[0-9\s\-]{6,20}$/', $form['phone'])) {
        $errors['phone'] = 'رقم الهاتف غير صالح';
    }

    if (!in_array($form['gender'], ['male', 'female'], true)) {
        $errors['gender'] = 'يرجى اختيار الجنس';
    }

    if (!in_array($form['status'], ['active', 'inactive'], true)) {
        $errors['status'] = 'حالة غير صالحة';
    }

    $date_of_birth = null;
    if ($form['date_of_birth'] !== '') {
        $dob = DateTime::createFromFormat('!Y-m-d', $form['date_of_birth']);
        if (!$dob || $dob->format('Y-m-d') !== $form['date_of_birth']) {
            $errors['date_of_birth'] = 'تاريخ الميلاد غير صالح';
        } elseif ($dob > new DateTime('today')) {
            $errors['date_of_birth'] = 'تاريخ الميلاد لا يمكن أن يكون في المستقبل';
        } else {
            $date_of_birth = $dob->format('Y-m-d');
        }
    }

    // العمر يُحسب من تاريخ الميلاد إن وُجد، وإلا يُؤخذ كما أُدخل
    $age = null;
    if ($date_of_birth) {
        $age = (new DateTime($date_of_birth))->diff(new DateTime('today'))->y;
        $form['age'] = $age;
    } elseif ($form['age'] !== '') {
        if (!ctype_digit((string)$form['age']) || (int)$form['age'] > 150) {
            $errors['age'] = 'العمر يجب أن يكون رقماً بين 0 و 150';
        } else {
            $age = (int)$form['age'];
        }
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'البريد الإلكتروني غير صالح';
    } elseif (mb_strlen($form['email']) > 100) {
        $errors['email'] = 'البريد الإلكتروني طويل جداً';
    }

    if ($form['emergency_contact'] !== '' && !preg_match('/^\+?[0-9\s\-]{6,20}$/', $form['emergency_contact'])) {
        $errors['emergency_contact'] = 'رقم هاتف الطوارئ غير صالح';
    }

    if ($form['blood_type'] !== '' && !in_array($form['blood_type'], $blood_types, true)) {
        $errors['blood_type'] = 'فصيلة دم غير صالحة';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $dob_changed = $date_of_birth !== ($patient['date_of_birth'] ?: null);
            $next_birthday_followup = $date_of_birth ? nextBirthdayDate($date_of_birth) : null;
            if (!$dob_changed) {
                $next_birthday_followup = $patient['next_birthday_followup'];
            }

            $stmt = $pdo->prepare("
                UPDATE patients SET
                    name = ?, phone = ?, age = ?, gender = ?, date_of_birth = ?, next_birthday_followup = ?,
                    address = ?, email = ?, emergency_contact = ?, medical_history = ?, allergies = ?,
                    blood_type = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $form['name'],
                $form['phone'],
                $age,
                $form['gender'],
                $date_of_birth,
                $next_birthday_followup,
                $form['address'] !== '' ? $form['address'] : null,
                $form['email'] !== '' ? $form['email'] : null,
                $form['emergency_contact'] !== '' ? $form['emergency_contact'] : null,
                $form['medical_history'] !== '' ? $form['medical_history'] : null,
                $form['allergies'] !== '' ? $form['allergies'] : null,
                $form['blood_type'] !== '' ? $form['blood_type'] : null,
                $form['status'],
                $patient_id
            ]);

            // مزامنة متابعة عيد الميلاد المعلّقة مع تاريخ الميلاد الجديد
            if ($dob_changed) {
                if ($next_birthday_followup) {
                    $stmt = $pdo->prepare("
                        UPDATE follow_ups SET follow_up_date = ?
                        WHERE patient_id = ? AND follow_up_type = 'birthday' AND status = 'pending'
                    ");
                    $stmt->execute([$next_birthday_followup, $patient_id]);

                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) FROM follow_ups
                            WHERE patient_id = ? AND follow_up_type = 'birthday' AND status = 'pending'
                        ");
                        $stmt->execute([$patient_id]);
                        if ((int)$stmt->fetchColumn() === 0) {
                            $stmt = $pdo->prepare("
                                INSERT INTO follow_ups (patient_id, follow_up_type, follow_up_date, follow_up_reason, priority, created_by)
                                VALUES (?, 'birthday', ?, 'متابعة عيد الميلاد السنوية - فحص وقائي', 'normal', ?)
                            ");
                            $stmt->execute([$patient_id, $next_birthday_followup, $_SESSION['user_id']]);
                        }
                    }
                } else {
                    // حُذف تاريخ الميلاد: إلغاء متابعة عيد الميلاد المعلّقة
                    $stmt = $pdo->prepare("
                        UPDATE follow_ups SET status = 'cancelled'
                        WHERE patient_id = ? AND follow_up_type = 'birthday' AND status = 'pending'
                    ");
                    $stmt->execute([$patient_id]);
                }
            }

            $pdo->commit();

            logActivity($_SESSION['user_id'], 'update', 'patients', $patient_id, "تم تعديل بيانات المريض: {$form['name']}");

            $_SESSION['patient_profile_success'] = 'تم حفظ تعديلات بيانات المريض بنجاح';
            header('Location: patient_profile.php?id=' . $patient_id);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "خطأ في حفظ التعديلات: " . $e->getMessage();
        }
    }
}

// Header configuration
$pageTitle = 'تعديل بيانات المريض';
$pageIcon = 'fas fa-user-edit';
$pageSubtitle = $patient['name'] ?? '';
$currentPage = 'patients';

function fieldValue($form, $key) {
    return htmlspecialchars((string)($form[$key] ?? ''));
}

function fieldError($errors, $key) {
    if (empty($errors[$key])) {
        return '';
    }
    return '<p class="text-sm text-red-600 mt-1"><i class="fas fa-exclamation-circle ml-1"></i>' . htmlspecialchars($errors[$key]) . '</p>';
}

function inputClass($errors, $key) {
    $border = empty($errors[$key]) ? 'border-gray-300' : 'border-red-500 bg-red-50';
    return "w-full p-3 border $border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent";
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
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 fade-in">
        <?php if (!$patient): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg">
                <i class="fas fa-exclamation-triangle ml-2"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php else: ?>

        <!-- Breadcrumb -->
        <div class="flex items-center justify-between mb-6">
            <div class="text-sm text-gray-600">
                <a href="patients.php" class="hover:text-blue-600">المرضى</a>
                <i class="fas fa-chevron-left mx-2 text-xs"></i>
                <a href="patient_profile.php?id=<?= $patient_id ?>" class="hover:text-blue-600"><?= htmlspecialchars($patient['name']) ?></a>
                <i class="fas fa-chevron-left mx-2 text-xs"></i>
                <span class="text-gray-800 font-semibold">تعديل البيانات</span>
            </div>
            <a href="patient_profile.php?id=<?= $patient_id ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                <i class="fas fa-arrow-right ml-1"></i>
                العودة لملف المريض
            </a>
        </div>

        <!-- Patient summary -->
        <div class="bg-gradient-to-r from-blue-600 to-purple-700 text-white rounded-lg shadow-lg p-6 mb-6 flex items-center justify-between">
            <div class="flex items-center">
                <div class="bg-white bg-opacity-20 p-4 rounded-full ml-4">
                    <i class="fas fa-user-edit text-2xl text-white"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold"><?= htmlspecialchars($patient['name']) ?></h2>
                    <p class="text-sm opacity-90">
                        آخر تحديث: <?= $patient['updated_at'] ? date('d/m/Y H:i', strtotime($patient['updated_at'])) : '—' ?>
                    </p>
                </div>
            </div>
            <div class="text-left">
                <div class="text-sm opacity-90">رقم الملف</div>
                <div class="text-2xl font-bold"><?= str_pad($patient['id'], 6, '0', STR_PAD_LEFT) ?></div>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-triangle ml-2"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-triangle ml-2"></i>
                <?= htmlspecialchars($errors['general'] ?? 'يرجى تصحيح الحقول المشار إليها أدناه') ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="patientEditForm" class="space-y-6" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['patient_edit_token']) ?>">

            <!-- Basic Information -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-user text-blue-600 ml-2"></i>
                    المعلومات الأساسية
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="name" class="block text-gray-700 font-semibold mb-2">الاسم الكامل *</label>
                        <input type="text" name="name" id="name" required maxlength="100"
                               value="<?= fieldValue($form, 'name') ?>"
                               class="<?= inputClass($errors, 'name') ?>">
                        <?= fieldError($errors, 'name') ?>
                    </div>

                    <div>
                        <label for="phone" class="block text-gray-700 font-semibold mb-2">رقم الهاتف *</label>
                        <input type="tel" name="phone" id="phone" required maxlength="20" dir="ltr"
                               value="<?= fieldValue($form, 'phone') ?>" placeholder="09xxxxxxxx"
                               class="<?= inputClass($errors, 'phone') ?> text-right">
                        <?= fieldError($errors, 'phone') ?>
                    </div>

                    <div>
                        <label for="gender" class="block text-gray-700 font-semibold mb-2">الجنس *</label>
                        <select name="gender" id="gender" required class="<?= inputClass($errors, 'gender') ?>">
                            <option value="">اختر الجنس</option>
                            <option value="male" <?= ($form['gender'] ?? '') === 'male' ? 'selected' : '' ?>>ذكر</option>
                            <option value="female" <?= ($form['gender'] ?? '') === 'female' ? 'selected' : '' ?>>أنثى</option>
                        </select>
                        <?= fieldError($errors, 'gender') ?>
                    </div>

                    <div>
                        <label for="dateOfBirth" class="block text-gray-700 font-semibold mb-2">تاريخ الميلاد</label>
                        <input type="date" name="date_of_birth" id="dateOfBirth" max="<?= date('Y-m-d') ?>"
                               value="<?= fieldValue($form, 'date_of_birth') ?>"
                               class="<?= inputClass($errors, 'date_of_birth') ?>">
                        <?= fieldError($errors, 'date_of_birth') ?>
                        <p class="text-xs text-gray-500 mt-1">عند إدخاله يُحسب العمر تلقائياً وتُحدَّث متابعة عيد الميلاد</p>
                    </div>

                    <div>
                        <label for="age" class="block text-gray-700 font-semibold mb-2">العمر</label>
                        <input type="number" name="age" id="age" min="0" max="150"
                               value="<?= fieldValue($form, 'age') ?>" placeholder="العمر بالسنوات"
                               class="<?= inputClass($errors, 'age') ?>">
                        <?= fieldError($errors, 'age') ?>
                    </div>

                    <div>
                        <label for="status" class="block text-gray-700 font-semibold mb-2">حالة الملف</label>
                        <select name="status" id="status" class="<?= inputClass($errors, 'status') ?>">
                            <option value="active" <?= ($form['status'] ?? '') === 'active' ? 'selected' : '' ?>>نشط</option>
                            <option value="inactive" <?= ($form['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>غير نشط</option>
                        </select>
                        <?= fieldError($errors, 'status') ?>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-address-book text-green-600 ml-2"></i>
                    معلومات الاتصال
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="email" class="block text-gray-700 font-semibold mb-2">البريد الإلكتروني</label>
                        <input type="email" name="email" id="email" maxlength="100" dir="ltr"
                               value="<?= fieldValue($form, 'email') ?>" placeholder="example@email.com"
                               class="<?= inputClass($errors, 'email') ?> text-right">
                        <?= fieldError($errors, 'email') ?>
                    </div>

                    <div>
                        <label for="emergency_contact" class="block text-gray-700 font-semibold mb-2">هاتف الطوارئ</label>
                        <input type="tel" name="emergency_contact" id="emergency_contact" maxlength="20" dir="ltr"
                               value="<?= fieldValue($form, 'emergency_contact') ?>" placeholder="رقم هاتف أحد الأقارب"
                               class="<?= inputClass($errors, 'emergency_contact') ?> text-right">
                        <?= fieldError($errors, 'emergency_contact') ?>
                    </div>

                    <div class="md:col-span-2">
                        <label for="address" class="block text-gray-700 font-semibold mb-2">العنوان</label>
                        <textarea name="address" id="address" rows="2"
                                  class="<?= inputClass($errors, 'address') ?>"
                                  placeholder="العنوان الكامل"><?= fieldValue($form, 'address') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Medical Information -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-heartbeat text-red-600 ml-2"></i>
                    المعلومات الطبية
                </h3>

                <div class="space-y-6">
                    <div class="md:w-1/2">
                        <label for="blood_type" class="block text-gray-700 font-semibold mb-2">فصيلة الدم</label>
                        <select name="blood_type" id="blood_type" class="<?= inputClass($errors, 'blood_type') ?>">
                            <option value="">غير محدد</option>
                            <?php foreach ($blood_types as $type): ?>
                                <option value="<?= $type ?>" <?= ($form['blood_type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= fieldError($errors, 'blood_type') ?>
                    </div>

                    <div>
                        <label for="medical_history" class="block text-gray-700 font-semibold mb-2">التاريخ المرضي</label>
                        <textarea name="medical_history" id="medical_history" rows="4"
                                  class="<?= inputClass($errors, 'medical_history') ?>"
                                  placeholder="أمراض مزمنة، أدوية، عمليات جراحية سابقة..."><?= fieldValue($form, 'medical_history') ?></textarea>
                    </div>

                    <div>
                        <label for="allergies" class="block text-red-700 font-semibold mb-2">
                            <i class="fas fa-exclamation-triangle ml-1"></i>
                            الحساسية
                        </label>
                        <textarea name="allergies" id="allergies" rows="2"
                                  class="<?= inputClass($errors, 'allergies') ?>"
                                  placeholder="حساسية من أدوية أو مواد (مثل البنسلين، اللاتكس...)"><?= fieldValue($form, 'allergies') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row gap-4">
                <button type="submit" id="saveButton"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center shadow-md">
                    <i class="fas fa-save ml-2"></i>
                    حفظ التعديلات
                </button>
                <a href="patient_profile.php?id=<?= $patient_id ?>"
                   class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-3 px-4 rounded-lg transition duration-200 text-center">
                    إلغاء
                </a>
            </div>
        </form>

        <?php endif; ?>
    </div>

    <script>
        const form = document.getElementById('patientEditForm');
        const dobInput = document.getElementById('dateOfBirth');
        const ageInput = document.getElementById('age');

        function ageFromDate(value) {
            const dob = new Date(value + 'T00:00:00');
            if (isNaN(dob)) return '';
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const beforeBirthday = today.getMonth() < dob.getMonth() ||
                (today.getMonth() === dob.getMonth() && today.getDate() < dob.getDate());
            if (beforeBirthday) age--;
            return age >= 0 ? age : '';
        }

        function syncAgeWithDob() {
            if (!dobInput || !ageInput) return;
            if (dobInput.value) {
                ageInput.value = ageFromDate(dobInput.value);
                ageInput.readOnly = true;
                ageInput.classList.add('bg-gray-100');
            } else {
                ageInput.readOnly = false;
                ageInput.classList.remove('bg-gray-100');
            }
        }

        dobInput?.addEventListener('change', syncAgeWithDob);
        syncAgeWithDob();

        // تنبيه عند مغادرة الصفحة مع وجود تعديلات غير محفوظة
        let isDirty = false;
        form?.addEventListener('input', () => { isDirty = true; });
        form?.addEventListener('change', () => { isDirty = true; });
        window.addEventListener('beforeunload', e => {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        form?.addEventListener('submit', e => {
            const name = form.querySelector('[name="name"]').value.trim();
            const phone = form.querySelector('[name="phone"]').value.trim();
            const gender = form.querySelector('[name="gender"]').value;

            if (!name || !phone || !gender) {
                e.preventDefault();
                alert('يرجى ملء الحقول المطلوبة: الاسم، رقم الهاتف، الجنس');
                return;
            }

            isDirty = false;
            const saveButton = document.getElementById('saveButton');
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> جاري الحفظ...';
        });
    </script>
</body>
</html>
