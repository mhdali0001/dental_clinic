<?php
require_once 'config/database.php';

echo "<h2>تحديث هيكل جدول مراحل العلاج</h2>";
echo "<p>هذا السكريبت سيقوم بتعديل جدول treatment_stages ليكون مرتبطاً بخيارات العلاج بدلاً من أنواع العلاج</p>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    echo "<h3>خطوات التحديث:</h3>";

    // 1. Check current table structure
    echo "<p>1. فحص الهيكل الحالي للجدول...</p>";
    $stmt = $pdo->query("DESCRIBE treatment_stages");
    $current_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($current_structure as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // 2. Create backup table
    echo "<p>2. إنشاء نسخة احتياطية من الجدول الحالي...</p>";
    $pdo->exec("DROP TABLE IF EXISTS treatment_stages_backup");
    $pdo->exec("CREATE TABLE treatment_stages_backup AS SELECT * FROM treatment_stages");
    echo "<p style='color: green;'>✓ تم إنشاء النسخة الاحتياطية بنجاح</p>";

    // 3. Get current data count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_stages");
    $current_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "<p>عدد السجلات الحالية: {$current_count}</p>";

    // 4. Drop and recreate table with new structure
    echo "<p>3. إعادة إنشاء الجدول بالهيكل الجديد...</p>";

    $pdo->exec("DROP TABLE IF EXISTS treatment_stages");

    $create_table_sql = "
        CREATE TABLE treatment_stages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            treatment_options_code INT NOT NULL,
            stage_order INT NOT NULL,
            title_ar VARCHAR(255) NOT NULL,
            description_ar TEXT,
            duration_ar VARCHAR(100),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_stage (treatment_options_code, stage_order),
            FOREIGN KEY (treatment_options_code) REFERENCES treatment_options(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($create_table_sql);
    echo "<p style='color: green;'>✓ تم إنشاء الجدول الجديد بنجاح</p>";

    // 5. Show new structure
    echo "<p>4. الهيكل الجديد للجدول:</p>";
    $stmt = $pdo->query("DESCRIBE treatment_stages");
    $new_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($new_structure as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // 6. Insert sample data
    echo "<p>5. إدراج بيانات تجريبية...</p>";

    // Get some treatment options to create sample stages
    $stmt = $pdo->query("SELECT id, treatment_type_code, option_code, name_ar FROM treatment_options LIMIT 5");
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($options)) {
        foreach ($options as $option) {
            // Insert 3 sample stages for each option
            $stages = [
                [
                    'title' => 'الفحص الأولي',
                    'description' => 'فحص شامل لحالة المريض وتقييم العلاج المطلوب',
                    'duration' => '30 دقيقة'
                ],
                [
                    'title' => 'التحضير والبدء',
                    'description' => 'تحضير المريض والأدوات اللازمة لبدء العلاج',
                    'duration' => '15 دقيقة'
                ],
                [
                    'title' => 'تنفيذ العلاج',
                    'description' => 'تنفيذ الإجراء العلاجي الأساسي',
                    'duration' => '45 دقيقة'
                ]
            ];

            foreach ($stages as $index => $stage) {
                $stmt = $pdo->prepare("
                    INSERT INTO treatment_stages
                    (treatment_options_code, stage_order, title_ar, description_ar, duration_ar, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");

                $stmt->execute([
                    $option['id'],
                    $index + 1,
                    $stage['title'],
                    $stage['description'],
                    $stage['duration']
                ]);
            }
        }

        $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_stages");
        $new_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "<p style='color: green;'>✓ تم إدراج {$new_count} مرحلة تجريبية</p>";
    }

    echo "<h3 style='color: green;'>✅ تم التحديث بنجاح!</h3>";
    echo "<p><strong>التغييرات الرئيسية:</strong></p>";
    echo "<ul>";
    echo "<li>إضافة عمود option_code لربط المراحل بخيارات العلاج المحددة</li>";
    echo "<li>إضافة مفتاح خارجي للضمان التوافق مع جدول treatment_options</li>";
    echo "<li>إضافة مفتاح فريد مركب لمنع التكرار</li>";
    echo "<li>إضافة أعمدة created_at و updated_at لتتبع التغييرات</li>";
    echo "</ul>";

    echo "<p><strong>ملاحظة:</strong> تم حفظ النسخة الاحتياطية في جدول treatment_stages_backup</p>";

    // 7. Show sample data
    echo "<p>6. عينة من البيانات الجديدة:</p>";
    $stmt = $pdo->query("
        SELECT ts.*, topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_options_code = topt.id
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
        LIMIT 10
    ");
    $sample_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sample_data)) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>نوع العلاج</th><th>كود الخيار</th><th>اسم الخيار</th><th>ترتيب المرحلة</th><th>عنوان المرحلة</th><th>المدة</th></tr>";
        foreach ($sample_data as $row) {
            echo "<tr>";
            echo "<td>{$row['treatment_type_code']}</td>";
            echo "<td>{$row['option_code']}</td>";
            echo "<td>{$row['option_name']}</td>";
            echo "<td>{$row['stage_order']}</td>";
            echo "<td>{$row['title_ar']}</td>";
            echo "<td>{$row['duration_ar']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ خطأ في قاعدة البيانات: " . $e->getMessage() . "</p>";

    // Try to restore from backup if it exists
    try {
        $pdo->exec("DROP TABLE IF EXISTS treatment_stages");
        $pdo->exec("CREATE TABLE treatment_stages AS SELECT * FROM treatment_stages_backup");
        echo "<p style='color: orange;'>⚠️ تم استعادة النسخة الاحتياطية</p>";
    } catch (Exception $restore_error) {
        echo "<p style='color: red;'>❌ فشل في استعادة النسخة الاحتياطية: " . $restore_error->getMessage() . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ خطأ عام: " . $e->getMessage() . "</p>";
}

echo "<p><a href='settings.php?tab=treatment_stages'>العودة إلى إعدادات مراحل العلاج</a></p>";
?>