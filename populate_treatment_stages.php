<?php
require_once 'config/database.php';

echo "<h2>Populate Treatment Stages with Test Data</h2>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    // First, check if we have treatment options
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_options WHERE is_active = 1");
    $optionsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<p>Active treatment options found: <strong>$optionsCount</strong></p>";

    if ($optionsCount == 0) {
        echo "<p style='color: red;'>❌ No active treatment options found. Cannot create treatment stages.</p>";
        echo "<p>Please ensure you have treatment options in the database first.</p>";
        exit;
    }

    // Check current treatment_stages count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_stages");
    $currentStages = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "<p>Current treatment stages: <strong>$currentStages</strong></p>";

    // Get some treatment options to create stages for
    $stmt = $pdo->query("
        SELECT id, treatment_type_code, option_code, name_ar
        FROM treatment_options
        WHERE is_active = 1
        ORDER BY treatment_type_code, option_code
        LIMIT 5
    ");
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Creating treatment stages for these options:</h3>";
    echo "<ul>";
    foreach ($options as $option) {
        echo "<li>ID: {$option['id']} - {$option['treatment_type_code']} - {$option['name_ar']}</li>";
    }
    echo "</ul>";

    // Clear existing stages first (optional - comment out if you want to keep existing data)
    $pdo->exec("DELETE FROM treatment_stages");
    echo "<p>✓ Cleared existing treatment stages</p>";

    // Define standard treatment stages templates
    $stageTemplates = [
        [
            'title' => 'الفحص الأولي والتشخيص',
            'description' => 'فحص شامل لحالة المريض وتقييم الحالة المرضية وتحديد خطة العلاج',
            'duration' => '30 دقيقة'
        ],
        [
            'title' => 'التحضير والتخدير',
            'description' => 'تحضير المريض والمنطقة المراد علاجها وتطبيق التخدير المناسب',
            'duration' => '15 دقيقة'
        ],
        [
            'title' => 'تنفيذ العلاج الأساسي',
            'description' => 'تنفيذ الإجراء العلاجي الرئيسي حسب نوع العلاج المحدد',
            'duration' => '45-60 دقيقة'
        ],
        [
            'title' => 'التشطيب والمراجعة',
            'description' => 'إنهاء الإجراءات العلاجية والتأكد من جودة العمل المنجز',
            'duration' => '20 دقيقة'
        ],
        [
            'title' => 'التعليمات والمتابعة',
            'description' => 'إعطاء التعليمات اللازمة للمريض وتحديد مواعيد المتابعة',
            'duration' => '10 دقائق'
        ]
    ];

    $totalInserted = 0;

    foreach ($options as $option) {
        echo "<h4>Creating stages for: {$option['name_ar']}</h4>";

        foreach ($stageTemplates as $index => $stage) {
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
                echo "<p>✓ Stage " . ($index + 1) . ": {$stage['title']}</p>";
                $totalInserted++;
            } else {
                echo "<p style='color: red;'>❌ Failed to insert stage: {$stage['title']}</p>";
            }
        }
    }

    echo "<h3 style='color: green;'>✅ Success!</h3>";
    echo "<p><strong>Total stages inserted:</strong> $totalInserted</p>";

    // Verify the data
    echo "<h3>Verification - Sample Data:</h3>";
    $stmt = $pdo->query("
        SELECT ts.treatment_option_id, ts.stage_order, ts.title_ar, ts.duration_ar,
               topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
        WHERE ts.is_active = 1
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
        LIMIT 10
    ");
    $verificationData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($verificationData) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Treatment Type</th><th>Option Name</th><th>Stage Order</th><th>Stage Title</th><th>Duration</th></tr>";
        foreach ($verificationData as $row) {
            echo "<tr>";
            echo "<td>{$row['treatment_type_code']}</td>";
            echo "<td>{$row['option_name']}</td>";
            echo "<td>{$row['stage_order']}</td>";
            echo "<td>{$row['title_ar']}</td>";
            echo "<td>{$row['duration_ar']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li>Go to the treatment form: <a href='treatment_new.php'>treatment_new.php</a></li>";
    echo "<li>Select a treatment type and option</li>";
    echo "<li>The treatment stages should now appear in the 'مراحل العلاج' column</li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; margin: 10px 0; width: 100%; }
    th, td { padding: 8px; text-align: right; border: 1px solid #ddd; }
    th { background-color: #f0f0f0; font-weight: bold; }
    h2, h3, h4 { color: #333; }
    ul { margin: 10px 0; }
    li { margin: 5px 0; }
</style>