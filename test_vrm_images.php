<?php
/**
 * Test script to verify VRM, images, and transmission extraction
 */
$config = require 'config.php';
require_once 'CarScraper.php';

// Create a test class to expose protected methods
class TestScraper extends CarScraper {
    public function testExtractDetails($html) {
        return $this->extractVehicleDetails($html);
    }
}

// Fetch a detail page
$url = 'https://www.systonautosltd.co.uk/vehicle/name/volvo-v40-2-0-d4-r-design-nav-plus-euro-6-s-s-5dr/';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_TIMEOUT => 30,
]);
$html = curl_exec($ch);
curl_close($ch);

echo "=== Testing extractVehicleDetails() ===\n\n";

$scraper = new TestScraper($config);
$details = $scraper->testExtractDetails($html);

echo "VRM (UK Registration): " . ($details['vrm'] ?? 'NOT FOUND') . "\n";
echo "Transmission: " . ($details['transmission'] ?? 'NOT FOUND') . "\n";
echo "Colour: " . ($details['colour'] ?? 'NOT FOUND') . "\n";
echo "Engine Size: " . ($details['engine_size'] ?? 'NOT FOUND') . "\n";
echo "Fuel Type: " . ($details['fuel_type'] ?? 'NOT FOUND') . "\n";
echo "Body Style: " . ($details['body_style'] ?? 'NOT FOUND') . "\n";
echo "Mileage: " . ($details['mileage'] ?? 'NOT FOUND') . "\n";

echo "\n=== All Images Found: " . count($details['all_images']) . " ===\n";
if (!empty($details['all_images'])) {
    foreach (array_slice($details['all_images'], 0, 5) as $i => $img) {
        echo "  " . ($i+1) . ". " . substr($img, 0, 80) . "...\n";
    }
    if (count($details['all_images']) > 5) {
        echo "  ... and " . (count($details['all_images']) - 5) . " more images\n";
    }
}

echo "\n=== Summary ===\n";
echo "✓ VRM: " . ($details['vrm'] ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ Transmission: " . ($details['transmission'] ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ Images: " . (count($details['all_images']) > 1 ? 'SUCCESS (' . count($details['all_images']) . ' found)' : 'FAILED') . "\n";
