<?php
// Analyze duplicate vehicles in database
$config = include 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );
    
    echo "=== DUPLICATE VEHICLES ANALYSIS ===\n\n";
    
    // 1. Check for incomplete URLs
    echo "[1] Incomplete Image URLs (missing extension):\n";
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images WHERE file_name LIKE 'https://%' AND (file_name NOT LIKE '%.jpg' AND file_name NOT LIKE '%.png' AND file_name NOT LIKE '%.webp')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Found " . $result['cnt'] . " incomplete URLs\n";
    
    $stmt = $pdo->query("SELECT vechicle_info_id, file_name FROM gyc_product_images WHERE file_name LIKE 'https://%' AND (file_name NOT LIKE '%.jpg' AND file_name NOT LIKE '%.png' AND file_name NOT LIKE '%.webp') LIMIT 10");
    $incomplete = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($incomplete as $img) {
        echo "  Vehicle ID {$img['vechicle_info_id']}: " . substr($img['file_name'], 0, 80) . "\n";
    }
    
    // 2. Summary stats
    echo "\n[2] Data Format Summary:\n";
    $stmt = $pdo->query("SELECT 
        SUM(CASE WHEN reg_no LIKE '%-' THEN 1 ELSE 0 END) as url_slug_count,
        SUM(CASE WHEN reg_no NOT LIKE '%-' AND reg_no != 'N/A' THEN 1 ELSE 0 END) as vrm_count
    FROM gyc_vehicle_info WHERE vendor_id = 432");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "URL-slug format (old): " . $result['url_slug_count'] . " vehicles\n";
    echo "VRM format (new):      " . $result['vrm_count'] . " vehicles\n";
    echo "Total:                 " . ($result['url_slug_count'] + $result['vrm_count']) . " vehicles\n";
    
    // 3. Find duplicate pairs
    echo "\n[3] Finding Duplicate Vehicle Pairs:\n";
    $stmt = $pdo->query("
        SELECT 
            v1.id as old_id, 
            v1.reg_no as old_reg_no,
            v2.id as new_id,
            v2.reg_no as new_reg_no,
            v1.vehicle_url,
            (SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v1.id) as old_images,
            (SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v2.id) as new_images
        FROM gyc_vehicle_info v1
        INNER JOIN gyc_vehicle_info v2 ON v1.attr_id = v2.attr_id AND v1.id < v2.id
        WHERE v1.vendor_id = 432 AND v2.vendor_id = 432
        AND v1.reg_no LIKE '%-'
        AND v2.reg_no NOT LIKE '%-'
        ORDER BY v1.id
        LIMIT 20
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($duplicates) . " duplicate pairs (showing first 20):\n\n";
    
    foreach($duplicates as $dup) {
        echo "PAIR:\n";
        echo "  OLD  (ID {$dup['old_id']}): reg_no='{$dup['old_reg_no']}', images={$dup['old_images']}\n";
        echo "  NEW  (ID {$dup['new_id']}): reg_no='{$dup['new_reg_no']}', images={$dup['new_images']}\n";
        echo "  URL: {$dup['vehicle_url']}\n\n";
    }
    
    // 4. Volvo V40 specific
    echo "[4] Volvo V40 Case Study (attr_id 613):\n";
    $stmt = $pdo->query("
        SELECT 
            v.id, 
            v.reg_no, 
            v.color,
            v.mileage,
            v.description,
            COUNT(i.id) as img_count 
        FROM gyc_vehicle_info v 
        LEFT JOIN gyc_product_images i ON v.id = i.vechicle_info_id 
        WHERE v.attr_id = 613 AND v.vendor_id = 432 
        GROUP BY v.id
    ");
    $volvo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($volvo as $v) {
        $desc_preview = substr($v['description'] ?? '', 0, 60);
        echo "  ID {$v['id']}: reg_no={$v['reg_no']}, color={$v['color']}, mileage={$v['mileage']}, images={$v['img_count']}\n";
        echo "       desc: {$desc_preview}...\n\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
