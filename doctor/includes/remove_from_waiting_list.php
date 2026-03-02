<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// التحقق من تسجيل الدخول ونوع المستخدم
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'doctor') {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالوصول']);
    exit;
}

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صحيحة']);
    exit;
}

// قراءة البيانات المرسلة
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['waiting_list_id']) || !is_numeric($input['waiting_list_id'])) {
    echo json_encode(['success' => false, 'message' => 'معرف قائمة الانتظار مطلوب']);
    exit;
}

$waitingListId = (int)$input['waiting_list_id'];

try {
    $db = getDB();
    $pdo = $db->getConnection();

    // التحقق من وجود السجل
    $checkStmt = $pdo->prepare("SELECT id FROM waiting_list WHERE id = ?");
    $checkStmt->execute([$waitingListId]);

    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'السجل غير موجود']);
        exit;
    }

    // حذف السجل من قائمة الانتظار
    $deleteStmt = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
    $result = $deleteStmt->execute([$waitingListId]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'تم إزالة المريض من قائمة الانتظار بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'فشل في إزالة المريض من قائمة الانتظار']);
    }

} catch (PDOException $e) {
    error_log("Database error in remove_from_waiting_list.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات']);
}
?>