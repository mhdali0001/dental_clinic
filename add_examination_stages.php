<?php
require_once 'config/database.php';

echo "<h2>إضافة مراحل العلاج لخيارات الفحص</h2>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    // First, get examination treatment options
    echo "<h3>1. خيارات الفحص الموجودة:</h3>";
    $stmt = $pdo->query("
        SELECT id, treatment_type_code, option_code, name_ar
        FROM treatment_options
        WHERE treatment_type_code = 'examination' AND is_active = 1
        ORDER BY option_code
    ");
    $examOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($examOptions)) {
        echo "<p style='color: red;'>❌ لا توجد خيارات فحص في قاعدة البيانات</p>";
        exit;
    }

    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>كود الخيار</th><th>اسم الخيار</th></tr>";
    foreach ($examOptions as $option) {
        echo "<tr>";
        echo "<td>{$option['id']}</td>";
        echo "<td>{$option['option_code']}</td>";
        echo "<td>{$option['name_ar']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Define stages specific to examination procedures
    $examinationStages = [
        [
            'title' => 'استقبال المريض والسجل الطبي',
            'description' => 'تسجيل بيانات المريض ومراجعة التاريخ الطبي والأسنان السابق',
            'duration' => '10 دقائق'
        ],
        [
            'title' => 'الفحص البصري الأولي',
            'description' => 'فحص بصري شامل للفم والأسنان واللثة والأنسجة المحيطة',
            'duration' => '15 دقيقة'
        ],
        [
            'title' => 'الفحص التفصيلي',
            'description' => 'فحص تفصيلي لكل سن ومنطقة في الفم باستخدام الأدوات المناسبة',
            'duration' => '20 دقيقة'
        ],
        [
            'title' => 'التشخيص وتسجيل النتائج',
            'description' => 'تشخيص الحالة وتسجيل جميع الملاحظات والمشاكل المكتشفة',
            'duration' => '10 دقائق'
        ],
        [
            'title' => 'وضع خطة العلاج',
            'description' => 'وضع خطة علاج شاملة ومناقشتها مع المريض',
            'duration' => '15 دقيقة'
        ]
    ];

    echo "<h3>2. إضافة مراحل الفحص:</h3>";

    $totalInserted = 0;

    foreach ($examOptions as $option) {
        echo "<h4>إضافة مراحل لخيار: {$option['name_ar']} (ID: {$option['id']})</h4>";

        // Check if stages already exist for this option
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM treatment_stages WHERE treatment_option_id = ?");
        $checkStmt->execute([$option['id']]);
        $existingCount = $checkStmt->fetchColumn();

        if ($existingCount > 0) {
            echo "<p style='color: orange;'>⚠️ يوجد بالفعل $existingCount مراحل لهذا الخيار - سيتم تخطيه</p>";
            continue;
        }

        foreach ($examinationStages as $index => $stage) {
            $stmt = $pdo->prepare("
                INSERT INTO treatment_stages
                (treatment_option_id, stage_order, title_ar, description_ar, duration_ar, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");

            $success = $stmt->execute([
                $option['id'],
                $index + 1,
                $stage['title'],
                $stage['description'],
                $stage['duration']
            ]);

            if ($success) {
                echo "<p>✓ مرحلة " . ($index + 1) . ": {$stage['title']}</p>";
                $totalInserted++;
            } else {
                echo "<p style='color: red;'>❌ فشل في إدراج المرحلة: {$stage['title']}</p>";
            }
        }
    }

    echo "<h3 style='color: green;'>✅ تم بنجاح!</h3>";
    echo "<p><strong>إجمالي المراحل المضافة:</strong> $totalInserted</p>";

    // Verify the complete data
    echo "<h3>3. التحقق من البيانات المحدثة:</h3>";
    $stmt = $pdo->query("
        SELECT ts.treatment_option_id, ts.stage_order, ts.title_ar,
               topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
        WHERE topt.treatment_type_code = 'examination' AND ts.is_active = 1
        ORDER BY topt.option_code, ts.stage_order
    ");
    $verificationData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($verificationData) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Option ID</th><th>اسم الخيار</th><th>ترتيب المرحلة</th><th>عنوان المرحلة</th></tr>";
        foreach ($verificationData as $row) {
            echo "<tr>";
            echo "<td>{$row['treatment_option_id']}</td>";
            echo "<td>{$row['option_name']}</td>";
            echo "<td>{$row['stage_order']}</td>";
            echo "<td>{$row['title_ar']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<p><strong>الخطوات التالية:</strong></p>";
    echo "<ul>";
    echo "<li>انتقل إلى نموذج العلاج: <a href='doctor/treatment_new.php'>treatment_new.php</a></li>";
    echo "<li>اختر نوع العلاج 'فحص عام'</li>";
    echo "<li>اختر أي خيار فحص</li>";
    echo "<li>يجب أن تظهر مراحل العلاج الآن في العمود 'مراحل العلاج'</li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>خطأ:</strong> " . $e->getMessage() . "</p>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; direction: rtl; }
    table { border-collapse: collapse; margin: 10px 0; width: 100%; }
    th, td { padding: 8px; text-align: right; border: 1px solid #ddd; }
    th { background-color: #f0f0f0; font-weight: bold; }
    h2, h3, h4 { color: #333; }
    ul { margin: 10px 0; }
    li { margin: 5px 0; }
</style>