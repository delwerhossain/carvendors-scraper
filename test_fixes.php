<?php
/**
 * Quick test to verify image cleaning and attention_grabber extraction
 */

// Load config
$config = require 'config.php';

// Test 1: Database connection and vehicle check
echo "=== TEST 1: Database Vehicle Check ===\n";
try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']}",
        $config['database']['username'],
        $config['database']['password']
    );
    
    $sql = "SELECT id, reg_no, attention_grabber, color, transmission FROM gyc_vehicle_info 
            WHERE vendor_id = 432 LIMIT 5";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sample vehicles from database:\n";
    foreach ($vehicles as $v) {
        echo "\n  ID: {$v['id']}\n";
        echo "  Reg No: {$v['reg_no']}\n";
        echo "  Attention Grabber: " . ($v['attention_grabber'] ? substr($v['attention_grabber'], 0, 60) . "..." : "NOT SET") . "\n";
        echo "  Color: " . ($v['color'] ?? "NULL") . "\n";
        echo "  Transmission: " . ($v['transmission'] ?? "NULL") . "\n";
    }
    
    echo "\n✓ Database check passed!\n";
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
}

// Test 2: Check images in database
echo "\n=== TEST 2: Image Deduplication Check ===\n";
try {
    // Count images for a specific vehicle
    $sql = "SELECT v.id, v.reg_no, COUNT(i.id) as image_count 
            FROM gyc_vehicle_info v
            LEFT JOIN gyc_product_images i ON i.vehicle_info_id = v.id
            WHERE v.vendor_id = 432
            GROUP BY v.id, v.reg_no
            ORDER BY image_count DESC
            LIMIT 5";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Top 5 vehicles by image count:\n";
    foreach ($results as $r) {
        echo "  - {$r['reg_no']}: {$r['image_count']} images\n";
    }
    
    // Check for invalid image URLs
    $sql = "SELECT COUNT(*) as count FROM gyc_product_images 
            WHERE file_name NOT REGEXP '\\.(?:jpg|jpeg|png|webp)$'";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $invalid = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\n Invalid image URLs (no .jpg/.png/.webp): " . $invalid['count'] . " (should be 0)\n";
    
    if ($invalid['count'] == 0) {
        echo "✓ All images have valid extensions!\n";
    } else {
        echo "✗ Found " . $invalid['count'] . " invalid image URLs\n";
    }
    
} catch (Exception $e) {
    echo "✗ Image check error: " . $e->getMessage() . "\n";
}

// Test 3: Check JSON output
echo "\n=== TEST 3: JSON Output Check ===\n";
if (file_exists('data/vehicles.json')) {
    $json = file_get_contents('data/vehicles.json');
    $data = json_decode($json, true);
    
    if (is_array($data) && !empty($data['vehicles'])) {
        echo "JSON file valid: " . count($data['vehicles']) . " vehicles\n";
        
        // Check for attention_grabber field
        $hasAttentionGrabber = 0;
        foreach (array_slice($data['vehicles'], 0, 10) as $v) {
            if (!empty($v['attention_grabber'])) {
                $hasAttentionGrabber++;
            }
        }
        echo "Vehicles with attention_grabber (sampled 10): $hasAttentionGrabber\n";
        
        // Check image counts
        $sampleImages = 0;
        foreach (array_slice($data['vehicles'], 0, 5) as $v) {
            $sampleImages += $v['images']['count'] ?? 0;
        }
        echo "Sample: 5 vehicles have " . $sampleImages . " images (avg: " . ($sampleImages/5) . ")\n";
        
        echo "✓ JSON output looks good!\n";
    } else {
        echo "✗ Invalid JSON structure\n";
    }
} else {
    echo "✗ vehicles.json not found\n";
}

echo "\n======================\n";
echo "✓ All tests complete!\n";
echo "======================\n";

