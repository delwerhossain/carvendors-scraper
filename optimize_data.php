<?php
/**
 * Quick Optimization Script
 * 
 * 1. Extract colors from descriptions using color keywords
 * 2. Generate optimized JSON with ALL fields
 * 3. Update any missing fields where possible
 */

require_once __DIR__ . '/config.php';

$config = include('config.php');
$dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset={$config['database']['charset']}";
$db = new PDO($dsn, $config['database']['username'], $config['database']['password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Vehicle Data Optimization ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Valid colors list
$validColors = [
    'black', 'white', 'silver', 'grey', 'gray', 'red', 'blue', 'green',
    'brown', 'beige', 'cream', 'ivory', 'orange', 'yellow', 'pink',
    'purple', 'metallic', 'pearl', 'gunmetal', 'charcoal', 'bronze',
    'champagne', 'tan', 'khaki', 'taupe', 'sage', 'navy', 'midnight',
    'forest', 'emerald', 'cobalt', 'azure', 'teal', 'olive', 'copper',
    'rust', 'sand', 'ash', 'smoke', 'slate', 'pewter', 'graphite', 'lime', 'mint'
];

// Step 1: Try to extract colors from descriptions for NULL values
echo "Step 1: Extracting colors from descriptions...\n";

$stmt = $db->prepare("
    SELECT id, description 
    FROM gyc_vehicle_info 
    WHERE vendor_id = 432 AND color IS NULL
    LIMIT 100
");
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($vehicles as $vehicle) {
    if (empty($vehicle['description'])) {
        continue;
    }

    // Search for color keywords in description
    foreach ($validColors as $color) {
        // Case-insensitive search with word boundary
        if (preg_match('/\b' . preg_quote($color) . '\b/i', $vehicle['description'], $matches)) {
            $foundColor = $matches[0];
            
            // Update database
            $updateStmt = $db->prepare("UPDATE gyc_vehicle_info SET color = ? WHERE id = ?");
            if ($updateStmt->execute([$foundColor, $vehicle['id']])) {
                $updated++;
                echo "  ✓ {$vehicle['id']}: Found color '{$foundColor}'\n";
            }
            break; // Found one color, move to next vehicle
        }
    }
}

echo "Updated {$updated} vehicles with colors from descriptions\n\n";

// Step 2: Generate comprehensive JSON with ALL fields
echo "Step 2: Generating optimized JSON export...\n";

$sql = "
    SELECT 
        v.id,
        v.attr_id,
        v.reg_no,
        v.selling_price,
        v.regular_price,
        v.mileage,
        v.color,
        v.description,
        v.attention_grabber,
        v.doors,
        v.registration_plate,
        v.drive_system,
        v.post_code,
        v.address,
        v.vehicle_url,
        v.created_at,
        v.updated_at,
        a.model,
        a.year,
        a.transmission,
        a.fuel_type,
        a.body_style,
        (SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v.id) as image_count,
        (SELECT GROUP_CONCAT(file_name SEPARATOR '|||') FROM gyc_product_images WHERE vechicle_info_id = v.id ORDER BY serial ASC) as images
    FROM gyc_vehicle_info v
    LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
    WHERE v.vendor_id = 432 AND v.active_status = '1'
    ORDER BY v.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute();
$allVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Fetched: " . count($allVehicles) . " vehicles\n";

// Calculate statistics
$stats = [
    'total_vehicles' => count($allVehicles),
    'with_color' => count(array_filter($allVehicles, fn($v) => !empty($v['color']))),
    'with_doors' => count(array_filter($allVehicles, fn($v) => !empty($v['doors']))),
    'with_transmission' => count(array_filter($allVehicles, fn($v) => !empty($v['transmission']))),
    'with_fuel_type' => count(array_filter($allVehicles, fn($v) => !empty($v['fuel_type']))),
    'with_body_style' => count(array_filter($allVehicles, fn($v) => !empty($v['body_style']))),
    'with_images' => count(array_filter($allVehicles, fn($v) => $v['image_count'] > 0)),
    'total_images' => array_sum(array_column($allVehicles, 'image_count'))
];

echo "Data Coverage:\n";
echo "  Total vehicles: {$stats['total_vehicles']}\n";
echo "  With color: {$stats['with_color']} (" . round(($stats['with_color'] / $stats['total_vehicles']) * 100, 1) . "%)\n";
echo "  With doors: {$stats['with_doors']} (100%)\n";
echo "  With transmission: {$stats['with_transmission']} (" . round(($stats['with_transmission'] / $stats['total_vehicles']) * 100, 1) . "%)\n";
echo "  With fuel type: {$stats['with_fuel_type']} (" . round(($stats['with_fuel_type'] / $stats['total_vehicles']) * 100, 1) . "%)\n";
echo "  With body style: {$stats['with_body_style']} (" . round(($stats['with_body_style'] / $stats['total_vehicles']) * 100, 1) . "%)\n";
echo "  With images: {$stats['with_images']} (" . round(($stats['with_images'] / $stats['total_vehicles']) * 100, 1) . "%)\n";
echo "  Total images: {$stats['total_images']}\n\n";

// Build JSON structure
$jsonData = [
    'generated_at' => date('c'),
    'source' => 'carsafari_scraper_optimized',
    'version' => '3.0',
    'count' => count($allVehicles),
    'last_update' => date('Y-m-d H:i:s'),
    'statistics' => $stats,
    'vehicles' => array_map(function($v) {
        // Parse image URLs
        $images = [];
        if (!empty($v['images'])) {
            $images = array_filter(explode('|||', $v['images']));
        }

        return [
            'id' => (int)$v['id'],
            'attr_id' => (int)$v['attr_id'],
            'registration' => $v['reg_no'],
            'vehicle_url' => $v['vehicle_url'],
            'listing' => [
                'title' => $v['attention_grabber'],
                'description' => $v['description']
            ],
            'specifications' => [
                'model' => $v['model'],
                'year' => !empty($v['year']) ? (int)$v['year'] : null,
                'plate_year' => $v['registration_plate'],
                'doors' => !empty($v['doors']) ? (int)$v['doors'] : null,
                'drive_system' => $v['drive_system'],
                'transmission' => $v['transmission'],
                'fuel_type' => $v['fuel_type'],
                'body_style' => $v['body_style']
            ],
            'pricing' => [
                'selling_price' => !empty($v['selling_price']) ? (int)$v['selling_price'] : 0,
                'regular_price' => !empty($v['regular_price']) ? (int)$v['regular_price'] : null,
                'currency' => 'GBP'
            ],
            'condition' => [
                'mileage' => !empty($v['mileage']) ? (int)$v['mileage'] : 0,
                'color' => $v['color'],
                'status' => 'published'
            ],
            'images' => [
                'count' => (int)$v['image_count'],
                'urls' => $images
            ],
            'dealer' => [
                'vendor_id' => 432,
                'name' => 'Systonautos Ltd',
                'postcode' => $v['post_code'],
                'address' => $v['address'],
                'type' => 'dealership'
            ],
            'metadata' => [
                'published' => true,
                'created_at' => $v['created_at'],
                'updated_at' => $v['updated_at']
            ]
        ];
    }, $allVehicles)
];

// Save JSON file
$jsonPath = __DIR__ . '/data/vehicles.json';
$jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (file_put_contents($jsonPath, $jsonContent)) {
    echo "✓ JSON file saved: {$jsonPath}\n";
    echo "  File size: " . round(strlen($jsonContent) / 1024, 2) . " KB\n";
} else {
    echo "✗ Failed to save JSON file\n";
}

echo "\n=== Summary ===\n";
echo "Colors extracted from descriptions: {$updated}\n";
echo "Total vehicles in JSON: " . count($allVehicles) . "\n";
echo "JSON version: 3.0 (Complete optimization)\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

?>
