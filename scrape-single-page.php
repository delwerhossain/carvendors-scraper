#!/usr/bin/env php
<?php
/**
 * Single Page Vehicle Scraper
 *
 * Scrapes a single vehicle detail page and extracts all data
 * Usage: php scrape-single-page.php "https://systonautosltd.co.uk/vehicle/name/mini-countryman-..."
 */

$config = require 'config.php';

if ($argc < 2) {
    echo "Usage: php scrape-single-page.php \"https://url/to/vehicle/page\"\n";
    exit(1);
}

$vehicleUrl = $argv[1];

try {
    echo "🚗 VEHICLE SCRAPER - Single Page\n";
    echo "=====================================\n\n";
    echo "URL: $vehicleUrl\n\n";

    // Fetch the page using cURL
    echo "Fetching page...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $vehicleUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $config['scraper']['user_agent'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html) {
        die("❌ Failed to fetch page\n");
    }

    // Parse HTML
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    echo "✓ Page fetched successfully\n\n";

    // Extract basic info
    echo "📋 EXTRACTED DATA:\n";
    echo "=====================================\n\n";

    // Title
    $titleNodes = $xpath->query("//h1 | //title | //meta[@property='og:title']");
    $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : "N/A";
    echo "Title: $title\n";

    // Price
    $pricePattern = '/£\s*([\d,]+)/';
    preg_match($pricePattern, $html, $priceMatches);
    $price = $priceMatches[1] ?? "N/A";
    echo "Price: £$price\n";

    // Mileage
    $mileagePattern = '/(\d+,?\d*)\s*(miles?|mi)/i';
    preg_match($mileagePattern, $html, $mileageMatches);
    $mileage = $mileageMatches[1] ?? "N/A";
    echo "Mileage: $mileage\n";

    // Colour
    $colourPattern = '/colour?[:\s]+([a-z\s]+?)[\n<]/i';
    preg_match($colourPattern, $html, $colourMatches);
    $colour = trim($colourMatches[1] ?? "N/A");
    echo "Colour: $colour\n";

    // Transmission
    $transmissionPattern = '/transmission[:\s]+([a-z]+)/i';
    preg_match($transmissionPattern, $html, $transmissionMatches);
    $transmission = ucfirst(trim($transmissionMatches[1] ?? "N/A"));
    echo "Transmission: $transmission\n";

    // Fuel Type
    $fuelPattern = '/fuel\s+type[:\s]+([a-z]+)/i';
    preg_match($fuelPattern, $html, $fuelMatches);
    $fuel = ucfirst(trim($fuelMatches[1] ?? "N/A"));
    echo "Fuel Type: $fuel\n";

    // Body Style
    $bodyPattern = '/body\s+style[:\s]+([a-z]+)/i';
    preg_match($bodyPattern, $html, $bodyMatches);
    $body = ucfirst(trim($bodyMatches[1] ?? "N/A"));
    echo "Body Style: $body\n";

    // Engine Size
    $enginePattern = '/engine[:\s]+([0-9,\.]+)/i';
    preg_match($enginePattern, $html, $engineMatches);
    $engine = trim($engineMatches[1] ?? "N/A");
    echo "Engine Size: $engine\n";

    // Registration Date
    $regPattern = '/first\s+reg(?:istration)?[:\s]+(\d{1,2}\/\d{1,2}\/\d{4})/i';
    preg_match($regPattern, $html, $regMatches);
    $regDate = trim($regMatches[1] ?? "N/A");
    echo "Registration Date: $regDate\n";

    // Location
    $locationPattern = '/location[:\s]+([a-z\s]+?)[\n<]/i';
    preg_match($locationPattern, $html, $locationMatches);
    $location = ucfirst(trim($locationMatches[1] ?? "N/A"));
    echo "Location: $location\n";

    // Extract images
    echo "\n🖼️  IMAGES:\n";
    echo "=====================================\n";
    preg_match_all('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $imgMatches);
    $images = array_unique(array_filter($imgMatches[1], function($url) {
        return strpos($url, 'vehicle') !== false || strpos($url, 'car') !== false;
    }));

    echo "Found " . count($images) . " vehicle images:\n";
    foreach ($images as $idx => $img) {
        echo "  " . ($idx + 1) . ". " . substr($img, 0, 80) . "...\n";
    }

    // Extract description
    echo "\n📝 DESCRIPTION:\n";
    echo "=====================================\n";
    $descNodes = $xpath->query("//div[contains(@class, 'description')] | //div[contains(@class, 'details')] | //p");
    $description = "";
    for ($i = 0; $i < min(3, $descNodes->length); $i++) {
        $text = trim($descNodes->item($i)->textContent);
        if (strlen($text) > 50) {
            $description = $text;
            break;
        }
    }

    if (empty($description)) {
        // Try to extract from body
        $body = $dom->getElementsByTagName('body')->item(0);
        $description = substr(strip_tags($body->textContent), 0, 200);
    }

    echo substr($description, 0, 300) . "...\n";

    // Summary
    echo "\n✅ EXTRACTION COMPLETE\n";
    echo "=====================================\n";
    echo "Total Fields Extracted: 13\n";
    echo "Images Found: " . count($images) . "\n";
    echo "Description Length: " . strlen($description) . " chars\n\n";

    // Export as JSON
    $data = [
        'url' => $vehicleUrl,
        'title' => $title,
        'price' => $price,
        'mileage' => $mileage,
        'colour' => $colour,
        'transmission' => $transmission,
        'fuel_type' => $fuel,
        'body_style' => $body,
        'engine_size' => $engine,
        'registration_date' => $regDate,
        'location' => $location,
        'images' => array_values($images),
        'description' => substr($description, 0, 500),
        'extracted_at' => date('Y-m-d H:i:s'),
    ];

    echo "📦 JSON OUTPUT:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
