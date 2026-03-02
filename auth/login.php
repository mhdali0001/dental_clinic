<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    // تم إزالة استقبال الـ role من POST لأننا سنجلبه من قاعدة البيانات

    // التحقق من صحة البيانات
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'يرجى إدخال اسم المستخدم وكلمة المرور';
        header('Location: ../index.php');
        exit;
    }

    $db = getDB();
    
    // البحث عن المستخدم بناءً على اسم المستخدم فقط
    // سيتم جلب الـ role تلقائياً من قاعدة البيانات
    $query = "SELECT id, username, password, full_name, role 
              FROM users 
              WHERE username = ? AND is_active = 1";
    
    $user = $db->selectOne($query, [$username]);

    if ($user && password_verify($password, $user['password'])) {
        // تسجيل الدخول الناجح
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role']; // جلب الـ role من قاعدة البيانات

        // تسجيل النشاط
        $loginDescription = 'تسجيل دخول ناجح - ' . getRoleNameInArabic($user['role']);
        logActivity($user['id'], 'login', 'users', $user['id'], $loginDescription);

        // توجيه المستخدم حسب دوره المحفوظ في قاعدة البيانات
        switch($user['role']) {
            case 'doctor':
                header('Location: ../doctor/dashboard.php');
                break;
            case 'nurse':
                header('Location: ../nurse/dashboard.php');
                break;
            case 'admin':
                header('Location: ../admin/dashboard.php');
                break;
            case 'receptionist':
                header('Location: ../reception/dashboard.php');
                break;
            default:
                // في حالة وجود دور غير محدد، توجيه إلى لوحة تحكم عامة
                header('Location: ../dashboard.php');
                break;
        }
        exit;
    } else {
        // في حالة فشل تسجيل الدخول
        $_SESSION['error'] = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        
        // تسجيل محاولة دخول فاشلة (اختياري)
        if ($user) {
            // المستخدم موجود لكن كلمة المرور خاطئة
            logActivity(0, 'failed_login', 'users', 0, 
                       'محاولة دخول فاشلة للمستخدم: ' . $username);
        }
        
        header('Location: ../index.php');
        exit;
    }
} else {
    header('Location: ../index.php');
    exit;
}

/**
 * دالة لتسجيل النشاطات
 */
function logActivity($userId, $action, $tableName, $recordId, $description) {
    try {
        $db = getDB();
        $query = "INSERT INTO activity_log (user_id, action, table_name, record_id, description, ip_address, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db->execute($query, [$userId, $action, $tableName, $recordId, $description, $ipAddress]);
    } catch (Exception $e) {
        // في حالة عدم وجود جدول activity_log، نتجاهل الخطأ
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * دالة للحصول على اسم الدور بالعربية
 */
function getRoleNameInArabic($role) {
    $roles = [
        'doctor' => 'طبيب',
        'nurse' => 'ممرض/ممرضة',
        'admin' => 'مدير النظام',
        'receptionist' => 'موظف استقبال'
    ];
    
    return $roles[$role] ?? $role;
}
?>