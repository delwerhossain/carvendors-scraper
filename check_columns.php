<?php
$config = require 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password']
);

$result = $pdo->query('DESCRIBE gyc_vehicle_info');
$columns = [];
while ($row = $result->fetch()) {
    $columns[] = $row['Field'];
}

echo "Checking for required columns:\n";
echo "  post_code: " . (in_array('post_code', $columns) ? 'EXISTS' : 'MISSING') . "\n";
echo "  address: " . (in_array('address', $columns) ? 'EXISTS' : 'MISSING') . "\n";
echo "  drive_position: " . (in_array('drive_position', $columns) ? 'EXISTS' : 'MISSING') . "\n";

if (!in_array('post_code', $columns)) {
    echo "Adding post_code column...\n";
    $pdo->exec('ALTER TABLE gyc_vehicle_info ADD COLUMN post_code VARCHAR(20)');
}
if (!in_array('address', $columns)) {
    echo "Adding address column...\n";
    $pdo->exec('ALTER TABLE gyc_vehicle_info ADD COLUMN address VARCHAR(255)');
}
if (!in_array('drive_position', $columns)) {
    echo "Adding drive_position column...\n";
    $pdo->exec('ALTER TABLE gyc_vehicle_info ADD COLUMN drive_position VARCHAR(50)');
}

echo "Columns ready!\n";
?>
