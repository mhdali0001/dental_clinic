<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// تسجيل نشاط تسجيل الخروج إذا كان المستخدم مسجل دخول
if (isset($_SESSION['user_id'])) {
    try {
        logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id'], 'تسجيل خروج');
    } catch (Exception $e) {
        // Ignore logging errors during logout
    }
}

// مسح جميع متغيرات الجلسة
$_SESSION = array();

// إذا كان يتم استخدام الكوكيز للجلسة، احذف الكوكي أيضاً
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// تدمير الجلسة نهائياً
session_destroy();

// إعادة توجيه إلى الصفحة الرئيسية مع تأخير للتأكد من مسح الجلسة
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ../index.php');
exit;
?>