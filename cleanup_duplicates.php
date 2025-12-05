<?php
/**
 * Cleanup Duplicate Vehicles
 * 
 * Removes 81 old duplicate vehicle records with URL-slug reg_no format
 * Keeps 82 new records with VRM format (properly enriched with images, color, mileage)
 */

$config = include 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );
    
    echo "=== DUPLICATE VEHICLE CLEANUP ===\n\n";
    
    // Count before
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
    $before = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "[1] Before cleanup: $before vehicles\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images");
    $images_before = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "    Images: $images_before\n\n";
    
    // Get IDs of duplicate records to delete (those with 1 image = old data)
    echo "[2] Finding records to delete (those with 1 image)...\n";
    $stmt = $pdo->prepare("
        SELECT v.id FROM gyc_vehicle_info v
        WHERE v.vendor_id = 432
        AND (SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v.id) = 1
    ");
    $stmt->execute();
    $old_records = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "    Found " . count($old_records) . " records with only 1 image (old/unrichched)\n";
    
    if (count($old_records) > 0) {
        // Step 1: Delete images from old records
        echo "[3] Deleting images from old records...\n";
        $placeholders = implode(',', array_fill(0, count($old_records), '?'));
        $stmt = $pdo->prepare("DELETE FROM gyc_product_images WHERE vechicle_info_id IN ($placeholders)");
        $stmt->execute($old_records);
        $images_deleted = $stmt->rowCount();
        echo "    Deleted $images_deleted images\n";
        
        // Step 2: Delete old vehicle records
        echo "[4] Deleting old vehicle records...\n";
        $stmt = $pdo->prepare("DELETE FROM gyc_vehicle_info WHERE id IN ($placeholders)");
        $stmt->execute($old_records);
        $vehicles_deleted = $stmt->rowCount();
        echo "    Deleted $vehicles_deleted vehicles\n";
    }
    
    // Count after
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
    $after = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "\n[5] After cleanup: $after vehicles\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images");
    $images_after = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "    Images: $images_after\n";
    
    echo "\n=== SUMMARY ===\n";
    echo "Vehicles: $before → $after (removed " . ($before - $after) . ")\n";
    echo "Images: $images_before → $images_after (removed " . ($images_before - $images_after) . ")\n";
    
    if ($after === 82) {
        echo "\n✓ SUCCESS! Cleaned up to 82 unique vehicles.\n";
        echo "\nNext steps:\n";
        echo "  1. php check_results.php    # Regenerate JSON output\n";
        echo "  2. Review data/vehicles.json # Verify no duplicates\n";
        echo "  3. git add sql/ && git commit -m 'Add duplicate cleanup script'\n";
    } else {
        echo "\n⚠ WARNING: Expected 82 vehicles but got $after\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
