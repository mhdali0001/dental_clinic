<?php
// صفحات السكرتاريا مشتركة مع الطبيب: تُعرض ترويسة الدور الحالي
// (نفس متغيرات الترويسة: $pageTitle, $pageIcon, $pageSubtitle, $currentPage)
if (($_SESSION['user_role'] ?? '') === 'doctor') {
    include __DIR__ . '/../../doctor/includes/doctor_header.php';
} else {
    include __DIR__ . '/nurse_header.php';
}
