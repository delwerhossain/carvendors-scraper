<?php
/**
 * Export Vehicles to JSON
 * Generates data/vehicles.json from current database state
 */

$config = include 'config.php';

/**
 * Calculate plate_year from UK registration plate code
 * UK plates: AB12CD where "12" = year code
 * 01-50: March-August (e.g., 09=2009, 50=2050)
 * 51-99: September-February (e.g., 59=2009, 99=2049)
 * Calculation: 01-50 use 2000+code, 51-99 use 1950+code
 */
function calculatePlateYear($plateCode) {
    $code = (int)$plateCode;
    if ($code <= 0 || $code > 99) {
        return null;
    }
    if ($code <= 50) {
        return 2000 + $code;
    } else {
        return 1950 + $code;
    }
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        ['charset' => 'utf8mb4']
    );
    
    echo "Exporting vehicles to JSON...\n";
    
    // Get all vehicles with their images
    $stmt = $pdo->query("
        SELECT 
            v.id, v.attr_id, v.reg_no, v.registration_plate, v.attention_grabber,
            a.model, a.year, a.transmission, a.fuel_type, a.body_style, a.engine_size,
            v.doors, v.drive_system,
            v.selling_price, v.regular_price, v.mileage, v.color,
            v.description, v.vehicle_url,
            v.post_code as postcode, v.address,
            v.active_status as published
        FROM gyc_vehicle_info v
        LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
        WHERE v.vendor_id = 432
        ORDER BY v.id DESC
    ");
    
    $vehicles = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Get images - ONLY large versions (no medium/other)
        $img_stmt = $pdo->prepare("
            SELECT file_name FROM gyc_product_images 
            WHERE vechicle_info_id = ? 
            AND file_name LIKE '%/large/%'
            ORDER BY serial
        ");
        $img_stmt->execute([$row['id']]);
        $images = $img_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build title from year + model + body_style
        $title_parts = [];
        if ($row['year']) $title_parts[] = $row['year'];
        if ($row['model']) $title_parts[] = $row['model'];
        if ($row['body_style']) $title_parts[] = $row['body_style'];
        $title = implode(' ', $title_parts) ?: 'Vehicle';
        
        $vehicle = [
            'id' => (int)$row['id'],
            'attr_id' => (int)$row['attr_id'],
            'reg_no' => $row['reg_no'],
            'registration_plate' => $row['registration_plate'],
            'plate_year' => calculatePlateYear($row['registration_plate']),
            'attention_grabber' => $row['attention_grabber'],
            'title' => $title,
            'model' => $row['model'],
            'year' => $row['year'] ? (int)$row['year'] : null,
            'doors' => $row['doors'] ? (int)$row['doors'] : null,
            'drive_system' => $row['drive_system'],
            'engine_size' => $row['engine_size'] ? (int)$row['engine_size'] : null,
            'selling_price' => $row['selling_price'] ? (int)$row['selling_price'] : null,
            'regular_price' => $row['regular_price'] ? (int)$row['regular_price'] : null,
            'mileage' => $row['mileage'] ? (int)$row['mileage'] : null,
            'color' => $row['color'],
            'transmission' => $row['transmission'],
            'fuel_type' => $row['fuel_type'],
            'body_style' => $row['body_style'],
            'description' => $row['description'],
            'postcode' => $row['postcode'],
            'address' => $row['address'],
            'vehicle_url' => $row['vehicle_url'],
            'images' => [
                'count' => count($images),
                'urls' => $images
            ],
            'dealer' => [
                'vendor_id' => 432,
                'name' => 'Systonautos Ltd',
                'postcode' => 'LE7 1NS',
                'address' => 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS'
            ],
            'published' => $row['published'] ? true : false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $vehicles[] = $vehicle;
    }
    
    $output = [
        'source' => 'CarVendors Scraper',
        'timestamp' => date('Y-m-d H:i:s'),
        'total_vehicles' => count($vehicles),
        'vehicles' => $vehicles
    ];
    
    // Write JSON
    $json_path = 'data/vehicles.json';
    file_put_contents($json_path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    echo "✓ Exported " . count($vehicles) . " vehicles to $json_path\n";
    
    // Show summary
    echo "\n=== JSON EXPORT SUMMARY ===\n";
    echo "Total vehicles: " . count($vehicles) . "\n";
    echo "File: $json_path\n";
    echo "Size: " . round(filesize($json_path) / 1024) . " KB\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
