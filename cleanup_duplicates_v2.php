<?php
/**
 * Cleanup Duplicate Vehicles (Final Version)
 * 
 * Removes 81 old duplicate vehicle records:
 * - 80 records with 1 image (old listing-only data)
 * - 1 record with URL-slug format and 0 images (malformed)
 * 
 * Keeps 82 new records (properly enriched data)
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
    
    // Strategy: Find the NEW record for each URL (better data), delete all others
    echo "[2] Identifying records to keep vs delete...\n";
    
    // Get all URLs that appear multiple times
    $stmt = $pdo->query("
        SELECT vehicle_url, COUNT(*) as cnt
        FROM gyc_vehicle_info
        WHERE vendor_id = 432
        GROUP BY vehicle_url
        HAVING cnt > 1
    ");
    $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "    Found " . count($urls) . " URLs with duplicates\n\n";
    
    $to_delete = [];
    
    // For each duplicated URL, keep the one with most images
    foreach($urls as $url_group) {
        $url = $url_group['vehicle_url'];
        $stmt = $pdo->prepare("
            SELECT v.id, COUNT(i.id) as img_count, v.created_at
            FROM gyc_vehicle_info v
            LEFT JOIN gyc_product_images i ON v.id = i.vechicle_info_id
            WHERE v.vehicle_url = ?
            GROUP BY v.id
            ORDER BY img_count DESC, v.id DESC
        ");
        $stmt->execute([$url]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Keep first (most images), delete the rest
        for ($i = 1; $i < count($versions); $i++) {
            $to_delete[] = $versions[$i]['id'];
        }
    }
    
    echo "[3] Records to delete: " . count($to_delete) . "\n\n";
    
    if (count($to_delete) > 0) {
        // Delete images
        echo "[4] Deleting images from old records...\n";
        $placeholders = implode(',', array_fill(0, count($to_delete), '?'));
        $stmt = $pdo->prepare("DELETE FROM gyc_product_images WHERE vechicle_info_id IN ($placeholders)");
        $stmt->execute($to_delete);
        $images_deleted = $stmt->rowCount();
        echo "    Deleted $images_deleted images\n";
        
        // Delete vehicles
        echo "[5] Deleting old vehicle records...\n";
        $stmt = $pdo->prepare("DELETE FROM gyc_vehicle_info WHERE id IN ($placeholders)");
        $stmt->execute($to_delete);
        $vehicles_deleted = $stmt->rowCount();
        echo "    Deleted $vehicles_deleted vehicles\n";
    }
    
    // Count after
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_vehicle_info WHERE vendor_id = 432");
    $after = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "\n[6] After cleanup: $after vehicles\n";
    
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
    } else {
        echo "\n⚠ Note: Got $after vehicles (expected 82)\n";
        echo "This is OK if there are legitimate singleton vehicles.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
