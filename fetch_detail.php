<?php
require_once 'config.php';

// Fetch a detail page to see structure
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

echo "=== LOOKING FOR VRM ===\n";
// Look for VRM patterns
if (preg_match('/<input[^>]*name=["\']vrm["\'][^>]*value=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
    echo "Found VRM input: " . $matches[1] . "\n";
} elseif (preg_match('/<input[^>]*value=["\']([^"\']+)["\'][^>]*name=["\']vrm["\'][^>]*>/i', $html, $matches)) {
    echo "Found VRM input (alt): " . $matches[1] . "\n";
}

// Also look in JavaScript
if (preg_match('/vrn["\']?\s*[:=]\s*["\']([A-Z0-9]+)["\']/', $html, $matches)) {
    echo "Found VRN in JS: " . $matches[1] . "\n";
}

// Look for registration patterns
if (preg_match('/["\']([A-Z]{2}[0-9]{2}\s?[A-Z]{3})["\']/', $html, $matches)) {
    echo "Found reg pattern (AA00 AAA): " . $matches[1] . "\n";
}

// Look around "vrm" anywhere in HTML - show more context
if (preg_match_all('/[^<>]{0,50}vrm[^<>]{0,100}/i', $html, $matches)) {
    echo "Context around 'vrm':\n";
    foreach (array_slice($matches[0], 0, 5) as $ctx) {
        echo "  " . trim($ctx) . "\n";
    }
}

echo "\n=== LOOKING FOR ALL IMAGES ===\n";
// Look for image gallery
if (preg_match_all('/data-src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)/i', $html, $matches)) {
    echo "Found " . count($matches[1]) . " data-src images:\n";
    foreach (array_slice($matches[1], 0, 10) as $img) {
        echo "  - " . $img . "\n";
    }
}

// Look for regular img src with stock images
if (preg_match_all('/<img[^>]+src=["\']([^"\']+\/stock\/[^"\']+)/i', $html, $matches)) {
    echo "\nFound " . count($matches[1]) . " stock images:\n";
    foreach (array_unique($matches[1]) as $img) {
        echo "  - " . $img . "\n";
    }
}

// Look for ANY large image URLs (typical car image patterns)
if (preg_match_all('/src=["\']([^"\']*(?:upload|image|stock|photo|media)[^"\']*\.(jpg|jpeg|png|webp))/i', $html, $matches)) {
    echo "\nFound " . count($matches[1]) . " potential vehicle images:\n";
    $unique = array_unique($matches[1]);
    foreach (array_slice($unique, 0, 15) as $img) {
        echo "  - " . $img . "\n";
    }
}

echo "\n=== TRANSMISSION/GEARBOX ===\n";
if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Transmission\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
    echo "Found Transmission: " . $matches[1] . "\n";
}
if (preg_match('/Gearbox[:\s]*([^<\n]+)/i', $html, $matches)) {
    echo "Found Gearbox: " . trim($matches[1]) . "\n";
}

// Show VC_SETTINGS if exists
if (preg_match('/var\s+VC_SETTINGS\s*=\s*\{([^;]+)\}/s', $html, $matches)) {
    echo "\n=== VC_SETTINGS OBJECT ===\n";
    $settings = substr($matches[0], 0, 1000);
    echo $settings . "\n";
}

// Look for thumbnail elements
echo "\n=== THUMBNAIL ELEMENTS ===\n";
if (preg_match_all('/<[^>]*(thumb|gallery|slider|carousel)[^>]*>/i', $html, $matches)) {
    echo "Found gallery/thumbnail elements: " . count($matches[0]) . "\n";
}
