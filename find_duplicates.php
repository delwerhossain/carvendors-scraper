<?php
// Find duplicate vehicles by comparing vehicle_url instead of reg_no
$config = include 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );
    
    echo "=== FINDING DUPLICATES BY VEHICLE_URL ===\n\n";
    
    // Find vehicles with same URL but different IDs (true duplicates)
    $stmt = $pdo->query("
        SELECT 
            vehicle_url,
            GROUP_CONCAT(id ORDER BY id) as ids,
            GROUP_CONCAT(reg_no ORDER BY id) as reg_nos,
            COUNT(*) as duplicate_count
        FROM gyc_vehicle_info
        WHERE vendor_id = 432
        GROUP BY vehicle_url
        HAVING duplicate_count > 1
        ORDER BY duplicate_count DESC
    ");
    
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($duplicates) . " duplicate URL groups:\n\n";
    
    foreach($duplicates as $dup) {
        echo "Vehicle URL: {$dup['vehicle_url']}\n";
        echo "  IDs: {$dup['ids']}\n";
        echo "  reg_nos: {$dup['reg_nos']}\n";
        echo "  Count: {$dup['duplicate_count']}\n";
        
        // Get details for each ID
        $ids = explode(',', $dup['ids']);
        foreach($ids as $id) {
            $detail_stmt = $pdo->query("
                SELECT v.id, v.reg_no, v.color, v.mileage, v.created_at,
                       COUNT(i.id) as img_count
                FROM gyc_vehicle_info v
                LEFT JOIN gyc_product_images i ON v.id = i.vechicle_info_id
                WHERE v.id = $id
                GROUP BY v.id
            ");
            $detail = $detail_stmt->fetch(PDO::FETCH_ASSOC);
            echo "    ID {$detail['id']}: reg_no='{$detail['reg_no']}', color={$detail['color']}, mileage={$detail['mileage']}, images={$detail['img_count']}, created={$detail['created_at']}\n";
        }
        echo "\n";
    }
    
    echo "\n=== SUMMARY ===\n";
    echo "Total vehicles with same URL (duplicates): " . count($duplicates) . "\n";
    echo "Total duplicate instances: " . array_sum(array_column($duplicates, 'duplicate_count')) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
