<?php
// Test file to verify logout functionality
session_start();

echo "<!DOCTYPE html>
<html lang='ar' dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>Test Logout</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .info { background: #e3f2fd; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { background: #c8e6c9; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .error { background: #ffcdd2; padding: 15px; margin: 10px 0; border-radius: 5px; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>اختبار وظيفة تسجيل الخروج</h1>";

// Check if session is active
if (isset($_SESSION['user_id'])) {
    echo "<div class='success'>";
    echo "<h2>الجلسة نشطة</h2>";
    echo "<p><strong>User ID:</strong> " . $_SESSION['user_id'] . "</p>";
    echo "<p><strong>User Name:</strong> " . ($_SESSION['full_name'] ?? 'N/A') . "</p>";
    echo "<p><strong>User Role:</strong> " . ($_SESSION['user_role'] ?? 'N/A') . "</p>";
    echo "</div>";
} else {
    echo "<div class='info'>";
    echo "<h2>لا توجد جلسة نشطة</h2>";
    echo "<p>الرجاء تسجيل الدخول أولاً</p>";
    echo "</div>";
}

// Check file paths
echo "<div class='info'>";
echo "<h2>مسارات الملفات</h2>";
echo "<p><strong>Current File:</strong> " . __FILE__ . "</p>";
echo "<p><strong>Logout Path 1:</strong> " . realpath('auth/logout.php') . "</p>";
echo "<p><strong>Logout Path 2:</strong> " . realpath('logout.php') . "</p>";
echo "<p><strong>Session Status:</strong> " . session_status() . " (1=disabled, 2=active, 3=none)</p>";
echo "</div>";

// Test buttons
echo "<div class='info'>";
echo "<h2>اختبار أزرار تسجيل الخروج</h2>";
echo "<button onclick=\"window.location.href='auth/logout.php'\">Logout via auth/logout.php</button><br>";
echo "<button onclick=\"window.location.href='logout.php'\">Logout via logout.php</button><br>";
echo "<button onclick=\"testLogoutAjax()\">Test Logout (AJAX)</button><br>";
echo "<a href='index.php'><button>العودة للصفحة الرئيسية</button></a>";
echo "</div>";

echo "<div id='ajax-result'></div>";

echo "<script>
function testLogoutAjax() {
    fetch('auth/logout.php')
        .then(response => {
            document.getElementById('ajax-result').innerHTML =
                '<div class=\"success\"><h3>Logout request sent!</h3><p>Redirecting...</p></div>';
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 1000);
        })
        .catch(error => {
            document.getElementById('ajax-result').innerHTML =
                '<div class=\"error\"><h3>Error:</h3><p>' + error + '</p></div>';
        });
}
</script>";

echo "</body></html>";
?>
