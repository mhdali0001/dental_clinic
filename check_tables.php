<?php
require_once 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();
$result = $conn->query('SHOW TABLES');
$tables = $result->fetchAll(PDO::FETCH_COLUMN);

echo "All tables in database:\n";
echo "======================\n";
foreach($tables as $table) {
    echo $table . "\n";
}

// Now get row count for each table
echo "\n\nTable Row Counts:\n";
echo "======================\n";
foreach($tables as $table) {
    $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
    $count = $countResult->fetch(PDO::FETCH_ASSOC);
    echo str_pad($table, 30) . " : " . $count['cnt'] . " rows\n";
}
