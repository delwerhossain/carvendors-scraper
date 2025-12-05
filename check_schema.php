<?php
$config = include 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    ['charset' => 'utf8mb4']
);

// Get column names
$stmt = $pdo->query("DESCRIBE gyc_product_images");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== ACTUAL TABLE STRUCTURE ===\n";
foreach($cols as $c) {
    echo "  {$c['Field']}\n";
}
?>
