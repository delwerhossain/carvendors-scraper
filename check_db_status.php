<?php
/**
 * Check database status
 */
$config = require 'config.php';

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['database']['host'],
    $config['database']['dbname'],
    $config['database']['charset']
);

$db = new PDO($dsn, $config['database']['username'], $config['database']['password']);

echo "=== DATABASE STATUS ===\n\n";

$stmt = $db->query('SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432');
$cnt = $stmt->fetch()['cnt'];
echo "Total vehicles: {$cnt}\n";

if ($cnt > 0) {
    echo "\nSample UK VRM values:\n";
    $stmt = $db->query('SELECT reg_no, color FROM gyc_vehicle_info WHERE vendor_id = 432 LIMIT 10');
    foreach ($stmt as $row) {
        echo "  {$row['reg_no']} | {$row['color']}\n";
    }
    
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM gyc_product_images pi JOIN gyc_vehicle_info vi ON pi.vechicle_info_id = vi.id WHERE vi.vendor_id = 432');
    echo "\nTotal images: " . $stmt->fetch()['cnt'] . "\n";
    
    // Check transmission
    $stmt = $db->query('SELECT DISTINCT va.transmission FROM gyc_vehicle_attribute va JOIN gyc_vehicle_info vi ON va.id = vi.attr_id WHERE vi.vendor_id = 432 AND va.transmission IS NOT NULL LIMIT 5');
    $trans = [];
    foreach ($stmt as $row) {
        $trans[] = $row['transmission'];
    }
    echo "\nTransmission values: " . implode(', ', $trans) . "\n";
}
