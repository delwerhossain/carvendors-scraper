<?php
/**
 * Clean Database for Fresh Scrape
 * 
 * This script prepares the database for a fresh scrape by:
 * 1. Backing up current data
 * 2. Removing ALL vehicle records and images
 * 3. Resetting auto-increment counters
 * 4. Keeping the table structure intact
 */

$config = include 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );
    
    echo "=== DATABASE CLEANUP FOR FRESH SCRAPE ===\n\n";
    
    // Get current counts
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
    $before_vehicles = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images");
    $before_images = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "[BEFORE CLEANUP]\n";
    echo "Vehicles (vendor 432): $before_vehicles\n";
    echo "Images: $before_images\n\n";
    
    // Create backup of current data (optional)
    echo "[CREATING BACKUP]\n";
    $backup_file = 'backups/vehicles_backup_' . date('Y-m-d_H-i-s') . '.json';
    @mkdir('backups', 0755, true);
    
    $stmt = $pdo->query("
        SELECT v.*, 
        GROUP_CONCAT(i.file_name SEPARATOR '|') as image_urls
        FROM gyc_vehicle_info v
        LEFT JOIN gyc_product_images i ON v.id = i.vechicle_info_id
        WHERE v.vendor_id = 432
        GROUP BY v.id
    ");
    $backup_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));
    echo "Backup saved to: $backup_file\n\n";
    
    // Delete all data
    echo "[DELETING DATA]\n";
    
    // Delete images first (has foreign key)
    echo "Deleting images...\n";
    $stmt = $pdo->prepare("DELETE FROM gyc_product_images");
    $stmt->execute();
    $images_deleted = $stmt->rowCount();
    echo "  Deleted $images_deleted images\n";
    
    // Delete vehicles
    echo "Deleting vehicles...\n";
    $stmt = $pdo->prepare("DELETE FROM gyc_vehicle_info WHERE vendor_id = 432");
    $stmt->execute();
    $vehicles_deleted = $stmt->rowCount();
    echo "  Deleted $vehicles_deleted vehicles\n";
    
    // Reset auto-increment
    echo "\nResetting auto-increment counters...\n";
    $pdo->exec("ALTER TABLE gyc_product_images AUTO_INCREMENT = 1");
    echo "  gyc_product_images: reset\n";
    
    // Note: Don't reset gyc_vehicle_info as we may have other vendors
    echo "  gyc_vehicle_info: not reset (has other vendors)\n";
    
    // Verify
    echo "\n[AFTER CLEANUP]\n";
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
    $after_vehicles = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images");
    $after_images = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "Vehicles (vendor 432): $after_vehicles\n";
    echo "Images: $after_images\n";
    
    echo "\n=== SUCCESS ===\n";
    echo "Database is clean and ready for fresh scrape!\n\n";
    echo "Next steps:\n";
    echo "1. Run: php scrape-carsafari.php\n";
    echo "2. Check: php find_duplicates.php (should show 0 duplicates)\n";
    echo "3. Export: php export_json.php\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
