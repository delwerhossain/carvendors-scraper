<?php
$config = include 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    ['charset' => 'utf8mb4']
);

echo "=== VEHICLE INFO + ATTRIBUTES ===\n";
$stmt = $pdo->query('
    SELECT v.id, v.reg_no, v.attention_grabber, v.description, 
           a.year, a.model, a.body_style, a.transmission
    FROM gyc_vehicle_info v
    LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
    LIMIT 5
');
foreach ($stmt as $row) {
    echo "\nID: {$row['id']}, Reg: {$row['reg_no']}\n";
    echo "Year: {$row['year']}, Model: {$row['model']}, Body: {$row['body_style']}\n";
    echo "Grabber: " . ($row['attention_grabber'] ?: 'NULL') . "\n";
    echo "Desc: " . substr($row['description'] ?? '', 0, 60) . "\n";
}
?>
