<?php
require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

// Get all tables
$result = $conn->query('SHOW FULL TABLES');
$allTables = $result->fetchAll(PDO::FETCH_NUM);

echo "DATABASE ANALYSIS - dental_clinic\n";
echo "===================================\n\n";

$regularTables = [];
$views = [];

foreach($allTables as $table) {
    if ($table[1] === 'BASE TABLE') {
        $regularTables[] = $table[0];
    } else {
        $views[] = $table[0];
    }
}

echo "REGULAR TABLES (" . count($regularTables) . "):\n";
echo str_repeat("-", 50) . "\n";
foreach($regularTables as $table) {
    try {
        $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        $count = $countResult->fetch(PDO::FETCH_ASSOC);
        echo str_pad($table, 35) . " : " . str_pad($count['cnt'], 8, ' ', STR_PAD_LEFT) . " rows\n";
    } catch (Exception $e) {
        echo str_pad($table, 35) . " : ERROR\n";
    }
}

echo "\n\nVIEWS (" . count($views) . "):\n";
echo str_repeat("-", 50) . "\n";
foreach($views as $view) {
    echo "- " . $view . "\n";
}

// Now search for table usage in PHP files
echo "\n\nSEARCHING FOR TABLE USAGE IN CODE...\n";
echo str_repeat("=", 50) . "\n\n";

$codebaseDir = __DIR__;
$phpFiles = [];

function scanDirectory($dir, &$files) {
    $items = scandir($dir);
    foreach($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && $item !== 'vendor' && $item !== 'node_modules') {
            scanDirectory($path, $files);
        } elseif (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        }
    }
}

scanDirectory($codebaseDir, $phpFiles);

$tableUsage = [];
foreach($regularTables as $table) {
    $tableUsage[$table] = [];
}

// Search for each table in PHP files
foreach($phpFiles as $file) {
    $content = file_get_contents($file);
    foreach($regularTables as $table) {
        // Look for table name in SQL queries
        if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $content)) {
            $tableUsage[$table][] = str_replace($codebaseDir . DIRECTORY_SEPARATOR, '', $file);
        }
    }
}

// Display results
echo "UNUSED TABLES:\n";
echo str_repeat("-", 50) . "\n";
$unusedCount = 0;
foreach($tableUsage as $table => $files) {
    if (empty($files)) {
        $unusedCount++;
        // Get row count
        try {
            $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
            $count = $countResult->fetch(PDO::FETCH_ASSOC);
            echo $unusedCount . ". " . str_pad($table, 35) . " (" . $count['cnt'] . " rows)\n";
        } catch (Exception $e) {
            echo $unusedCount . ". " . str_pad($table, 35) . " (ERROR)\n";
        }
    }
}

if ($unusedCount === 0) {
    echo "All tables are being used!\n";
}

echo "\n\nTABLES WITH LOW USAGE (1-3 files):\n";
echo str_repeat("-", 50) . "\n";
foreach($tableUsage as $table => $files) {
    if (count($files) > 0 && count($files) <= 3) {
        echo "- " . str_pad($table, 35) . " (" . count($files) . " files)\n";
        foreach($files as $file) {
            echo "    " . $file . "\n";
        }
    }
}

echo "\n\nMOST USED TABLES:\n";
echo str_repeat("-", 50) . "\n";
arsort($tableUsage);
$count = 0;
foreach($tableUsage as $table => $files) {
    if ($count >= 10) break;
    if (count($files) > 0) {
        echo ($count + 1) . ". " . str_pad($table, 35) . " (" . count($files) . " files)\n";
        $count++;
    }
}

echo "\n\nANALYSIS COMPLETE!\n";
