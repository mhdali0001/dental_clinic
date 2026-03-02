<?php
require_once 'config/database.php';

echo "<h1>Treatment Stages Debug Information</h1>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    echo "<h2>1. Database Structure Check</h2>";

    // Check treatment_stages table structure
    echo "<h3>Treatment Stages Table Structure:</h3>";
    $stmt = $pdo->query("DESCRIBE treatment_stages");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<h2>2. Data Check</h2>";

    // Check if treatment_option_id column exists
    $hasOptionId = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'treatment_option_id') {
            $hasOptionId = true;
            break;
        }
    }

    echo "<p><strong>Has treatment_option_id column:</strong> " . ($hasOptionId ? "✅ YES" : "❌ NO") . "</p>";

    // Count total stages
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM treatment_stages");
    $total = $stmt->fetchColumn();
    echo "<p><strong>Total treatment stages:</strong> $total</p>";

    if ($hasOptionId) {
        // Check stages with treatment_option_id
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM treatment_stages WHERE treatment_option_id IS NOT NULL");
        $withOptionId = $stmt->fetchColumn();
        echo "<p><strong>Stages with treatment_option_id:</strong> $withOptionId</p>";

        // Show sample data
        echo "<h3>Sample Treatment Stages Data:</h3>";
        $stmt = $pdo->query("
            SELECT ts.id, ts.treatment_option_id, ts.stage_order, ts.title_ar, ts.is_active,
                   topt.name_ar as option_name, topt.treatment_type_code
            FROM treatment_stages ts
            LEFT JOIN treatment_options topt ON ts.treatment_option_id = topt.id
            LIMIT 10
        ");
        $sampleData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($sampleData) {
            echo "<table border='1'>";
            echo "<tr><th>ID</th><th>Option ID</th><th>Stage Order</th><th>Title</th><th>Option Name</th><th>Treatment Type</th><th>Active</th></tr>";
            foreach ($sampleData as $row) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>{$row['treatment_option_id']}</td>";
                echo "<td>{$row['stage_order']}</td>";
                echo "<td>{$row['title_ar']}</td>";
                echo "<td>{$row['option_name']}</td>";
                echo "<td>{$row['treatment_type_code']}</td>";
                echo "<td>" . ($row['is_active'] ? '✅' : '❌') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ No treatment stages found</p>";
        }
    }

    echo "<h2>3. Treatment Options Check</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM treatment_options WHERE is_active = 1");
    $activeOptions = $stmt->fetchColumn();
    echo "<p><strong>Active treatment options:</strong> $activeOptions</p>";

    if ($activeOptions > 0) {
        echo "<h3>Sample Treatment Options:</h3>";
        $stmt = $pdo->query("
            SELECT id, treatment_type_code, option_code, name_ar
            FROM treatment_options
            WHERE is_active = 1
            LIMIT 5
        ");
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Treatment Type</th><th>Option Code</th><th>Name</th></tr>";
        foreach ($options as $option) {
            echo "<tr>";
            echo "<td>{$option['id']}</td>";
            echo "<td>{$option['treatment_type_code']}</td>";
            echo "<td>{$option['option_code']}</td>";
            echo "<td>{$option['name_ar']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<h2>4. PHP Query Test (from treatment_new.php)</h2>";

    // Test the exact query used in treatment_new.php (FIXED VERSION)
    $stmt = $pdo->query("
        SELECT ts.treatment_options_code, ts.stage_order, ts.title_ar, ts.description_ar, ts.duration_ar,
               topt.id as option_id, topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.treatment_options_code = topt.id
        WHERE ts.is_active = TRUE
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
    ");
    $treatment_stages_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p><strong>Query result count:</strong> " . count($treatment_stages_data) . "</p>";

    if ($treatment_stages_data) {
        // Organize data by option ID like in treatment_new.php
        $treatment_stages = [];
        foreach ($treatment_stages_data as $stage) {
            $option_id = $stage['treatment_options_code'];
            if (!isset($treatment_stages[$option_id])) {
                $treatment_stages[$option_id] = [];
            }
            $treatment_stages[$option_id][] = $stage;
        }

        echo "<p><strong>Organized by option IDs:</strong></p>";
        echo "<pre>" . json_encode($treatment_stages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    } else {
        echo "<p>❌ No data returned from the query</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>

<style>
    table { border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px; text-align: left; }
    th { background-color: #f0f0f0; }
    h1, h2, h3 { color: #333; }
    pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }
</style>