<?php
$config = require __DIR__ . '/config.php';
try {
    $db = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );

    // Get settings
    $settings = $db->query("SELECT @@group_concat_max_len as gc, @@max_allowed_packet as mp")->fetch(PDO::FETCH_ASSOC);
    echo "MySQL Settings:\n";
    echo "  group_concat_max_len: " . number_format($settings['gc']) . " bytes\n";
    echo "  max_allowed_packet: " . number_format($settings['mp']) . " bytes\n\n";

    // Check vehicle 1550 images (NOTE: column has typo: vechicle_info_id)
    $stmt = $db->query("
        SELECT vechicle_info_id, COUNT(*) as cnt, 
               SUM(CHAR_LENGTH(file_name)) as total_len
        FROM gyc_product_images 
        WHERE vechicle_info_id = 1550
        GROUP BY vechicle_info_id
    ");
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($info) {
        echo "Vehicle 1550 Images:\n";
        echo "  Actual count: " . $info['cnt'] . "\n";
        echo "  Total length: " . number_format($info['total_len']) . " chars\n";
        echo "  With separators (|||): ~" . number_format($info['total_len'] + ($info['cnt'] - 1) * 3) . " chars\n";
    }

    // Get last image URL to check if truncated
    $last = $db->query("SELECT file_name FROM gyc_product_images WHERE vechicle_info_id = 1550 ORDER BY serial DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "\n  Last image URL:\n";
    echo "    " . $last['file_name'] . "\n";
    echo "    Length: " . strlen($last['file_name']) . " chars\n";
    echo "    Ends with .jpg? " . (substr($last['file_name'], -4) === '.jpg' ? 'YES ✓' : 'NO ✗') . "\n";

    // Test GROUP_CONCAT
    echo "\n\nTesting GROUP_CONCAT:\n";
    $concat = $db->query("
        SELECT GROUP_CONCAT(file_name SEPARATOR '|||') as images
        FROM gyc_product_images 
        WHERE vechicle_info_id = 1550
        ORDER BY serial ASC
    ")->fetch(PDO::FETCH_ASSOC);
    
    $imgs = array_filter(explode('|||', $concat['images']));
    echo "  GROUP_CONCAT returned: " . count($imgs) . " images (actual: " . $info['cnt'] . ")\n";
    if (count($imgs) < $info['cnt']) {
        echo "  ⚠️  TRUNCATED! Missing " . ($info['cnt'] - count($imgs)) . " images\n";
        echo "  Last image in concat: " . substr(end($imgs), -50) . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
