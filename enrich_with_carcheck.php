<?php
/**
 * Enrich Vehicle Data with CarCheck.co.uk
 * 
 * This script:
 * 1. Finds vehicles with NULL color values
 * 2. Fetches color data from carcheck.co.uk
 * 3. Updates database with new color values
 * 4. Regenerates JSON with all fields
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/CarCheckIntegration.php';

// Set up database connection
$config = include('config.php');
$dsn = "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset={$config['database']['charset']}";
$db = new PDO($dsn, $config['database']['username'], $config['database']['password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize CarCheck integration
$carCheck = new CarCheckIntegration();

echo "=== CarVendors Enrichment with CarCheck.co.uk ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Step 1: Find vehicles with NULL color
echo "Step 1: Fetching vehicles with NULL color...\n";
$stmt = $db->prepare("
    SELECT id, reg_no, color 
    FROM gyc_vehicle_info 
    WHERE vendor_id = 432 AND color IS NULL
    LIMIT 81
");
$stmt->execute();
$nullColorVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found: " . count($nullColorVehicles) . " vehicles with NULL color\n\n";

// Step 2: Fetch color from carcheck and update
$updated = 0;
$failed = 0;

foreach ($nullColorVehicles as $vehicle) {
    echo "Processing: {$vehicle['reg_no']} (ID: {$vehicle['id']})...\n";
    
    // Fetch data from carcheck
    $carCheckData = $carCheck->fetchVehicleData($vehicle['reg_no']);
    
    if (!empty($carCheckData['color'])) {
        // Update color in database
        $updateStmt = $db->prepare("
            UPDATE gyc_vehicle_info 
            SET color = :color 
            WHERE id = :id
        ");
        
        try {
            $updateStmt->execute([
                ':color' => $carCheckData['color'],
                ':id' => $vehicle['id']
            ]);
            
            echo "  ✓ Updated with color: {$carCheckData['color']}\n";
            $updated++;
            
        } catch (Exception $e) {
            echo "  ✗ Failed to update: " . $e->getMessage() . "\n";
            $failed++;
        }
    } else {
        echo "  - No color found on carcheck\n";
        $failed++;
    }
    
    // Politeness delay
    sleep(1);
}

echo "\nStep 2 Results: {$updated} updated, {$failed} failed\n\n";

// Step 3: Generate complete JSON with ALL fields
echo "Step 3: Generating complete JSON export...\n";

$stmt = $db->prepare("
    SELECT 
        v.id,
        v.attr_id,
        v.reg_no,
        v.selling_price,
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
        (SELECT COUNT(*) FROM gyc_product_images WHERE vehicle_info_id = v.id) as image_count,
        (SELECT GROUP_CONCAT(file_name SEPARATOR '|') FROM gyc_product_images WHERE vehicle_info_id = v.id) as images
    FROM gyc_vehicle_info v
    LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
    WHERE v.vendor_id = 432
    ORDER BY v.created_at DESC
");

$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Fetched: " . count($vehicles) . " vehicles\n";

// Format JSON with all fields
$jsonData = [
    'generated_at' => date('c'),
    'source' => 'carsafari_scraper_with_carcheck',
    'count' => count($vehicles),
    'last_update' => date('Y-m-d H:i:s'),
    'statistics' => [
        'total_vehicles' => count($vehicles),
        'with_color' => count(array_filter($vehicles, fn($v) => !empty($v['color']))),
        'with_doors' => count(array_filter($vehicles, fn($v) => !empty($v['doors']))),
        'with_transmission' => count(array_filter($vehicles, fn($v) => !empty($v['transmission']))),
        'with_fuel_type' => count(array_filter($vehicles, fn($v) => !empty($v['fuel_type']))),
        'total_images' => array_sum(array_column($vehicles, 'image_count'))
    ],
    'vehicles' => array_map(function($v) {
        // Parse image URLs
        $images = [];
        if (!empty($v['images'])) {
            $images = array_filter(explode('|', $v['images']));
        }

        return [
            'id' => (int)$v['id'],
            'attr_id' => (int)$v['attr_id'],
            'reg_no' => $v['reg_no'],
            'title' => $v['attention_grabber'],
            'model' => $v['model'],
            'year' => (int)$v['year'],
            'plate_year' => $v['registration_plate'],
            'doors' => !empty($v['doors']) ? (int)$v['doors'] : null,
            'selling_price' => (int)$v['selling_price'],
            'mileage' => (int)$v['mileage'],
            'color' => $v['color'],
            'transmission' => $v['transmission'],
            'fuel_type' => $v['fuel_type'],
            'body_style' => $v['body_style'],
            'drive_system' => $v['drive_system'],
            'description' => $v['description'],
            'postcode' => $v['post_code'],
            'address' => $v['address'],
            'vehicle_url' => $v['vehicle_url'],
            'images' => [
                'count' => (int)$v['image_count'],
                'urls' => $images
            ],
            'published' => true,
            'created_at' => $v['created_at'],
            'updated_at' => $v['updated_at']
        ];
    }, $vehicles)
];

// Save JSON file
$jsonFile = __DIR__ . '/data/vehicles_complete.json';
$jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (file_put_contents($jsonFile, $jsonContent)) {
    echo "✓ JSON saved to: {$jsonFile}\n";
} else {
    echo "✗ Failed to save JSON\n";
}

echo "\n=== Summary ===\n";
echo "Colors updated: {$updated}\n";
echo "JSON vehicles: " . count($vehicles) . "\n";
echo "Total images: " . $jsonData['statistics']['total_images'] . "\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

?>
