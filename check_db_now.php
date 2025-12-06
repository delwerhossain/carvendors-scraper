<?php
require 'config.php';

try {
    $pdo = new PDO("mysql:host={$config['database']['host']};dbname={$config['database']['dbname']}", 
        $config['database']['username'], $config['database']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check a few vehicles
    $stmt = $pdo->query("SELECT id, reg_no, attention_grabber, description FROM gyc_vehicle_info WHERE vendor_id=432 LIMIT 5");
    echo "=== Current Data in Database ===\n\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']}\n";
        echo "  VRM: {$row['reg_no']}\n";
        echo "  Attention Grabber: " . htmlspecialchars(substr($row['attention_grabber'] ?? '', 0, 80)) . "\n";
        echo "  Description: " . htmlspecialchars(substr($row['description'] ?? '', 0, 80)) . "\n\n";
    }
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
