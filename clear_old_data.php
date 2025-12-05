<?php
/**
 * Clear old scraped data with wrong reg_no format (URL slugs)
 */
$config = require 'config.php';

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['database']['host'],
    $config['database']['dbname'],
    $config['database']['charset']
);

$db = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Clearing old data with URL slug reg_no...\n";

// Delete images for vehicles with URL slug reg_no
$sql = "DELETE pi FROM gyc_product_images pi
        JOIN gyc_vehicle_info vi ON pi.vechicle_info_id = vi.id
        WHERE vi.vendor_id = 432
          AND vi.reg_no LIKE '%-%-%'";
$result = $db->exec($sql);
echo "Deleted {$result} images\n";

// Delete attributes
$sql = "DELETE va FROM gyc_vehicle_attribute va
        JOIN gyc_vehicle_info vi ON va.id = vi.attr_id
        WHERE vi.vendor_id = 432
          AND vi.reg_no LIKE '%-%-%'";
$result = $db->exec($sql);
echo "Deleted {$result} attributes\n";

// Delete vehicles
$sql = "DELETE FROM gyc_vehicle_info 
        WHERE vendor_id = 432 
          AND reg_no LIKE '%-%-%'";
$result = $db->exec($sql);
echo "Deleted {$result} vehicles\n";

// Show remaining
$stmt = $db->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
$count = $stmt->fetch()['cnt'];
echo "\nRemaining vehicles with vendor_id=432: {$count}\n";
echo "Done!\n";
