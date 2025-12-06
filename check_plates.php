<?php
$config = include 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    ['charset' => 'utf8mb4']
);

echo "=== REGISTRATION PLATE ANALYSIS ===\n";
$stmt = $pdo->query('SELECT id, reg_no, registration_plate FROM gyc_vehicle_info LIMIT 10');
foreach ($stmt as $row) {
    echo "ID: {$row['id']}, VRM: {$row['reg_no']}, Plate: {$row['registration_plate']}\n";
}

// Check unique plate values
echo "\n=== UNIQUE PLATE VALUES ===\n";
$stmt = $pdo->query('SELECT DISTINCT registration_plate FROM gyc_vehicle_info ORDER BY registration_plate');
$plates = [];
foreach ($stmt as $row) {
    if ($row['registration_plate']) {
        $plates[] = $row['registration_plate'];
    }
}
echo "First 20: " . implode(', ', array_slice($plates, 0, 20)) . "\n";
?>
