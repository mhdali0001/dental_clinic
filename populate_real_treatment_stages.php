<?php
require_once 'config/database.php';

echo "<h2>إضافة بيانات مراحل العلاج الحقيقية</h2>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    // مسح البيانات القديمة
    echo "<h3>1. مسح البيانات القديمة:</h3>";
    $pdo->exec("DELETE FROM treatment_stages");
    echo "<p>✓ تم مسح البيانات القديمة</p>";

    // جلب جميع خيارات العلاج
    echo "<h3>2. جلب خيارات العلاج:</h3>";
    $stmt = $pdo->query("
        SELECT id, treatment_type_code, option_code, name_ar
        FROM treatment_options
        WHERE is_active = 1
        ORDER BY treatment_type_code, option_code
    ");
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p>تم العثور على " . count($options) . " خيار علاج</p>";

    // تعريف مراحل العلاج الحقيقية لكل نوع
    $treatmentStagesByType = [
        'examination' => [
            [
                'title' => 'استقبال المريض وفتح الملف',
                'description' => 'استقبال المريض، تسجيل البيانات الأساسية، فتح الملف الطبي وتحديث المعلومات الشخصية',
                'duration' => '10 دقائق'
            ],
            [
                'title' => 'أخذ التاريخ المرضي',
                'description' => 'سؤال المريض عن التاريخ المرضي للأسنان، الأدوية المتناولة، الحساسية، والشكاوى الحالية',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'الفحص البصري الأولي',
                'description' => 'فحص بصري شامل للفم والأسنان واللثة والأنسجة المحيطة لتحديد المشاكل الظاهرة',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'الفحص التفصيلي بالأدوات',
                'description' => 'استخدام المرآة والمسبار لفحص كل سن بدقة وتحديد التسوس والمشاكل الأخرى',
                'duration' => '25 دقيقة'
            ],
            [
                'title' => 'التشخيص ووضع خطة العلاج',
                'description' => 'تحليل النتائج، وضع التشخيص النهائي، وإعداد خطة علاج شاملة ومناقشتها مع المريض',
                'duration' => '20 دقيقة'
            ]
        ],

        'cleaning' => [
            [
                'title' => 'الفحص الأولي',
                'description' => 'فحص حالة الأسنان واللثة لتحديد مستوى التنظيف المطلوب',
                'duration' => '10 دقائق'
            ],
            [
                'title' => 'إزالة الجير',
                'description' => 'استخدام أدوات إزالة الجير لتنظيف الترسبات الصلبة من على الأسنان',
                'duration' => '30 دقيقة'
            ],
            [
                'title' => 'تنظيف البلاك',
                'description' => 'إزالة طبقة البلاك الناعمة والبكتيريا من سطح الأسنان',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'تلميع الأسنان',
                'description' => 'تلميع سطح الأسنان باستخدام معجون خاص لجعلها ناعمة ولامعة',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'التعليمات والنصائح',
                'description' => 'تقديم نصائح للعناية بالأسنان وتحديد موعد المراجعة القادمة',
                'duration' => '10 دقائق'
            ]
        ],

        'filling' => [
            [
                'title' => 'التحضير والتخدير',
                'description' => 'تنظيف المنطقة وتطبيق التخدير الموضعي حسب الحاجة',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'إزالة التسوس',
                'description' => 'إزالة الجزء المتسوس من السن باستخدام الحفار الطبي',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'تحضير التجويف',
                'description' => 'تنظيف وتحضير التجويف وتطبيق المواد اللاصقة',
                'duration' => '10 دقائق'
            ],
            [
                'title' => 'وضع الحشوة',
                'description' => 'وضع مادة الحشو في التجويف وتشكيلها حسب شكل السن',
                'duration' => '25 دقيقة'
            ],
            [
                'title' => 'التشطيب والتلميع',
                'description' => 'تنعيم الحشوة وتلميعها وفحص الإطباق للتأكد من الراحة',
                'duration' => '15 دقيقة'
            ]
        ],

        'crown' => [
            [
                'title' => 'الفحص والتخطيط',
                'description' => 'فحص السن وتحديد نوع التاج المناسب وأخذ القياسات الأولية',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'تحضير السن',
                'description' => 'برد السن وتشكيله ليناسب التاج الجديد',
                'duration' => '45 دقيقة'
            ],
            [
                'title' => 'أخذ الطبعة',
                'description' => 'أخذ طبعة دقيقة للسن المحضر وللأسنان المقابلة',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'وضع التاج المؤقت',
                'description' => 'تركيب تاج مؤقت لحماية السن حتى جاهزية التاج النهائي',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'تركيب التاج النهائي',
                'description' => 'إزالة التاج المؤقت وتركيب التاج النهائي وفحص الإطباق',
                'duration' => '30 دقيقة'
            ]
        ],

        'extraction' => [
            [
                'title' => 'الفحص والتقييم',
                'description' => 'فحص السن المراد قلعه وتقييم صعوبة العملية',
                'duration' => '10 دقائق'
            ],
            [
                'title' => 'التخدير',
                'description' => 'تطبيق التخدير الموضعي والانتظار حتى يصبح فعالاً',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'قلع السن',
                'description' => 'قلع السن باستخدام الأدوات المناسبة بحذر وعناية',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'تنظيف المكان',
                'description' => 'تنظيف مكان القلع من أي بقايا وفحص عدم وجود كسور',
                'duration' => '10 دقائق'
            ],
            [
                'title' => 'الرعاية اللاحقة',
                'description' => 'وضع الشاش، إعطاء التعليمات للعناية، ووصف الأدوية إذا لزم',
                'duration' => '15 دقيقة'
            ]
        ],

        'root_canal' => [
            [
                'title' => 'التشخيص والتخطيط',
                'description' => 'فحص السن وتأكيد الحاجة لعلاج العصب وشرح الإجراء للمريض',
                'duration' => '15 دقيقة'
            ],
            [
                'title' => 'التخدير وفتح السن',
                'description' => 'تطبيق التخدير الموضعي وفتح منطقة الوصول لحجرة العصب',
                'duration' => '20 دقيقة'
            ],
            [
                'title' => 'إزالة العصب المصاب',
                'description' => 'إزالة العصب والأنسجة المصابة من داخل قنوات الجذر',
                'duration' => '45 دقيقة'
            ],
            [
                'title' => 'تنظيف وتشكيل القنوات',
                'description' => 'تنظيف القنوات وتشكيلها وتطهيرها للتحضير للحشو',
                'duration' => '30 دقيقة'
            ],
            [
                'title' => 'حشو القنوات',
                'description' => 'حشو قنوات الجذر بمادة خاصة وإغلاق فتحة الوصول',
                'duration' => '25 دقيقة'
            ]
        ]
    ];

    echo "<h3>3. إضافة مراحل العلاج لكل خيار:</h3>";

    $totalInserted = 0;
    $insertStmt = $pdo->prepare("
        INSERT INTO treatment_stages
        (treatment_option_id, stage_order, title_ar, description_ar, duration_ar, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");

    foreach ($options as $option) {
        $treatmentType = $option['treatment_type_code'];

        echo "<h4>إضافة مراحل لخيار: {$option['name_ar']} ({$treatmentType})</h4>";

        // استخدام مراحل محددة حسب نوع العلاج أو مراحل عامة
        $stages = $treatmentStagesByType[$treatmentType] ?? $treatmentStagesByType['examination'];

        foreach ($stages as $index => $stage) {
            $success = $insertStmt->execute([
                $option['id'],
                $index + 1,
                $stage['title'],
                $stage['description'],
                $stage['duration']
            ]);

            if ($success) {
                echo "<p style='margin-left: 20px;'>✓ {$stage['title']}</p>";
                $totalInserted++;
            } else {
                echo "<p style='color: red; margin-left: 20px;'>❌ فشل: {$stage['title']}</p>";
            }
        }
    }

    echo "<h3 style='color: green;'>✅ تم بنجاح!</h3>";
    echo "<p><strong>إجمالي المراحل المضافة:</strong> $totalInserted</p>";

    // التحقق من البيانات
    echo "<h3>4. التحقق من البيانات المضافة:</h3>";
    $stmt = $pdo->query("
        SELECT
            ts.treatment_option_id,
            ts.stage_order,
            ts.title_ar,
            ts.duration_ar,
            topt.name_ar as option_name,
            topt.treatment_type_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
        WHERE ts.is_active = 1
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
        LIMIT 20
    ");
    $sampleData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sampleData) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>نوع العلاج</th><th>اسم الخيار</th><th>المرحلة</th><th>عنوان المرحلة</th><th>المدة</th></tr>";
        foreach ($sampleData as $row) {
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

    // إحصائيات نهائية
    echo "<h3>5. إحصائيات نهائية:</h3>";
    $stmt = $pdo->query("
        SELECT
            topt.treatment_type_code,
            COUNT(ts.id) as stages_count
        FROM treatment_options topt
        LEFT JOIN treatment_stages ts ON topt.id = ts.treatment_option_id
        WHERE topt.is_active = 1
        GROUP BY topt.treatment_type_code
        ORDER BY topt.treatment_type_code
    ");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>نوع العلاج</th><th>عدد المراحل</th></tr>";
    foreach ($stats as $stat) {
        echo "<tr><td>{$stat['treatment_type_code']}</td><td>{$stat['stages_count']}</td></tr>";
    }
    echo "</table>";

    echo "<p><strong>الخطوات التالية:</strong></p>";
    echo "<ul>";
    echo "<li>انتقل إلى: <a href='doctor/treatment_new.php'>treatment_new.php</a></li>";
    echo "<li>اختر أي نوع علاج وخيار</li>";
    echo "<li>ستظهر المراحل الحقيقية المناسبة لنوع العلاج</li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>خطأ:</strong> " . $e->getMessage() . "</p>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; direction: rtl; }
    table { border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px; text-align: right; border: 1px solid #ddd; }
    th { background-color: #f0f0f0; font-weight: bold; }
    h2, h3, h4 { color: #333; }
    ul { margin: 10px 0; }
    li { margin: 5px 0; }
</style>