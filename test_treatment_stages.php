<?php
require_once 'config/database.php';

echo "<h1>Treatment Stages Test - Complete Verification</h1>";
echo "<div style='background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<strong>This script will test if treatment stages are working correctly after the fix.</strong>";
echo "</div>";

try {
    $db = getDB();
    $pdo = $db->getConnection();

    echo "<h2>✅ Step 1: Database Connection Test</h2>";
    echo "<p style='color: green;'>✓ Successfully connected to database</p>";

    // Test 1: Check table structure
    echo "<h2>📋 Step 2: Table Structure Verification</h2>";

    $stmt = $pdo->query("DESCRIBE treatment_stages");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasOptionId = false;
    $hasOptionsCode = false;

    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Column Name</th><th>Type</th><th>Status</th></tr>";
    foreach ($columns as $column) {
        $status = "✓ OK";
        if ($column['Field'] === 'treatment_option_id') {
            $hasOptionId = true;
            $status = "✓ FOUND (Current)";
        } elseif ($column['Field'] === 'treatment_options_code') {
            $hasOptionsCode = true;
            $status = "⚠️ FOUND (New)";
        }
        echo "<tr><td>{$column['Field']}</td><td>{$column['Type']}</td><td>$status</td></tr>";
    }
    echo "</table>";

    if ($hasOptionId && !$hasOptionsCode) {
        echo "<p style='color: green;'>✓ Table uses old column name 'treatment_option_id' - Code is correctly configured for this</p>";
    } elseif ($hasOptionsCode && !$hasOptionId) {
        echo "<p style='color: orange;'>⚠️ Table uses new column name 'treatment_options_code' - Code needs adjustment</p>";
    } elseif ($hasOptionId && $hasOptionsCode) {
        echo "<p style='color: red;'>❌ Table has both column names - This may cause conflicts</p>";
    } else {
        echo "<p style='color: red;'>❌ Neither column found - Table structure issue</p>";
    }

    // Test 2: Check data
    echo "<h2>📊 Step 3: Data Verification</h2>";

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_stages WHERE is_active = 1");
    $stageCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM treatment_options WHERE is_active = 1");
    $optionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<p><strong>Active treatment stages:</strong> $stageCount</p>";
    echo "<p><strong>Active treatment options:</strong> $optionCount</p>";

    if ($stageCount == 0) {
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<strong>⚠️ No treatment stages found!</strong><br>";
        echo "You need to run: <a href='populate_treatment_stages.php'>populate_treatment_stages.php</a>";
        echo "</div>";
    } else {
        echo "<p style='color: green;'>✓ Treatment stages data found</p>";
    }

    // Test 3: Test the exact query from treatment_new.php
    echo "<h2>🔍 Step 4: Query Test (from treatment_new.php)</h2>";

    $columnName = $hasOptionId ? 'treatment_option_id' : 'treatment_options_code';

    $query = "
        SELECT ts.$columnName, ts.stage_order, ts.title_ar, ts.description_ar, ts.duration_ar,
               topt.id as option_id, topt.name_ar as option_name, topt.treatment_type_code, topt.option_code
        FROM treatment_stages ts
        LEFT JOIN treatment_options topt ON ts.$columnName = topt.id
        WHERE ts.is_active = TRUE
        ORDER BY topt.treatment_type_code, topt.option_code, ts.stage_order
    ";

    echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
    echo "<strong>Testing Query:</strong><br>";
    echo "<code>" . htmlspecialchars($query) . "</code>";
    echo "</div>";

    $treatment_stages_stmt = $pdo->query($query);
    $treatment_stages_data = $treatment_stages_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p><strong>Query returned:</strong> " . count($treatment_stages_data) . " records</p>";

    if (count($treatment_stages_data) > 0) {
        echo "<p style='color: green;'>✓ Query successful - Data retrieved</p>";

        // Organize data like in treatment_new.php
        $treatment_stages = [];
        foreach ($treatment_stages_data as $stage) {
            $option_id = $stage[$columnName];
            if (!isset($treatment_stages[$option_id])) {
                $treatment_stages[$option_id] = [];
            }
            $treatment_stages[$option_id][] = $stage;
        }

        echo "<h3>Organized Data Structure (for JavaScript):</h3>";
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px; max-height: 300px; overflow-y: auto;'>";
        echo "<pre>";
        echo "treatmentStages = " . json_encode($treatment_stages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "</pre>";
        echo "</div>";

        // Show sample table
        echo "<h3>Sample Retrieved Data:</h3>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Option ID</th><th>Treatment Type</th><th>Option Name</th><th>Stage Order</th><th>Stage Title</th></tr>";
        foreach (array_slice($treatment_stages_data, 0, 5) as $stage) {
            echo "<tr>";
            echo "<td>{$stage[$columnName]}</td>";
            echo "<td>{$stage['treatment_type_code']}</td>";
            echo "<td>{$stage['option_name']}</td>";
            echo "<td>{$stage['stage_order']}</td>";
            echo "<td>{$stage['title_ar']}</td>";
            echo "</tr>";
        }
        echo "</table>";

    } else {
        echo "<p style='color: red;'>❌ Query returned no data</p>";
    }

    // Test 4: Simulate JavaScript logic
    echo "<h2>⚡ Step 5: Frontend Logic Simulation</h2>";

    if (!empty($treatment_stages)) {
        $hasTemplateStages = false;
        foreach (array_keys($treatment_stages) as $optionId) {
            if (isset($treatment_stages[$optionId]) && count($treatment_stages[$optionId]) > 0) {
                $hasTemplateStages = true;
                break;
            }
        }

        if ($hasTemplateStages) {
            echo "<p style='color: green;'>✓ JavaScript logic would show treatment stages column</p>";
            echo "<p><strong>Number of options with stages:</strong> " . count($treatment_stages) . "</p>";
        } else {
            echo "<p style='color: red;'>❌ JavaScript logic would hide treatment stages column</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ No data available for JavaScript</p>";
    }

    // Final recommendations
    echo "<h2>🎯 Step 6: Final Status & Recommendations</h2>";

    if ($stageCount > 0 && count($treatment_stages_data) > 0) {
        echo "<div style='background: #d1e7dd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h3 style='color: #0f5132; margin-top: 0;'>✅ SUCCESS - Treatment Stages Should Work!</h3>";
        echo "<p><strong>Next steps:</strong></p>";
        echo "<ol>";
        echo "<li>Go to: <a href='doctor/treatment_new.php' target='_blank'>treatment_new.php</a></li>";
        echo "<li>Select a treatment type</li>";
        echo "<li>Select a treatment option</li>";
        echo "<li>The treatment stages column should appear with stages</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h3 style='color: #721c24; margin-top: 0;'>⚠️ Issues Found</h3>";
        echo "<p><strong>Problems to fix:</strong></p>";
        echo "<ul>";
        if ($stageCount == 0) {
            echo "<li>No treatment stages data - Run <a href='populate_treatment_stages.php'>populate_treatment_stages.php</a></li>";
        }
        if (count($treatment_stages_data) == 0) {
            echo "<li>Query returning no data - Check database relationships</li>";
        }
        echo "</ul>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3 style='color: #721c24;'>❌ Error During Testing</h3>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
    table { border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px 12px; text-align: right; border: 1px solid #ddd; }
    th { background-color: #f0f0f0; font-weight: bold; }
    h1, h2, h3 { color: #333; }
    code { background: #f1f1f1; padding: 2px 4px; border-radius: 3px; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
    a { color: #0066cc; text-decoration: none; }
    a:hover { text-decoration: underline; }
</style>