<?php
/**
 * Clean JSON Export - Remove duplicate vehicles with invalid reg_no
 * Keep only vehicles with proper UK registration number format
 */

$json = json_decode(file_get_contents('data/vehicles.json'), true);

// UK registration number pattern: Can have letters, numbers, hyphens (but not all lowercase/slug format)
// Valid: WP66UEX, MJ64YNN, LF63CRK, etc.
// Invalid: volvo-v40-2-0-d4-r-design-nav-plus-euro-6-s-s-5dr, nissan-micra-1-2-acenta-cvt-euro-5-5dr
function isValidUKRegNumber($reg_no) {
    // Valid UK reg: contains uppercase AND digits (or just uppercase/digits, no hyphens or all lowercase)
    // Invalid if it looks like a URL slug (contains hyphens and all lowercase words)
    
    if (empty($reg_no)) return false;
    
    // If it contains hyphens and lowercase letters, it's likely a slug, not a reg
    if (preg_match('/-/', $reg_no) && preg_match('/[a-z]/', $reg_no)) {
        return false;
    }
    
    // Valid UK regs are typically 2-3 letters + 2 digits + 2-3 letters
    // Or older formats with variations
    // Should have uppercase letters and digits
    if (preg_match('/[A-Z]/', $reg_no) && preg_match('/\d/', $reg_no)) {
        return true;
    }
    
    return false;
}

echo "═══════════════════════════════════════════════════════\n";
echo "  CLEANING JSON - Removing duplicate vehicles\n";
echo "═══════════════════════════════════════════════════════\n\n";

// Track which vehicle_urls to keep (first occurrence with valid reg_no wins)
$seenUrls = [];
$cleanedVehicles = [];
$removed = [];
$invalidRegCount = 0;

foreach ($json['vehicles'] as $v) {
    $url = $v['vehicle_url'];
    $hasValidReg = isValidUKRegNumber($v['reg_no']);
    
    // Skip vehicles with invalid reg_no entirely (not just duplicates)
    if (!$hasValidReg) {
        $removed[] = $v['id'];
        $invalidRegCount++;
        continue;
    }
    
    if (!isset($seenUrls[$url])) {
        // First time seeing this URL
        $seenUrls[$url] = [
            'vehicle_id' => $v['id'],
            'reg_no' => $v['reg_no'],
            'has_valid_reg' => $hasValidReg
        ];
        $cleanedVehicles[] = $v;
    } else {
        // Already seen this URL - keep the first one
        $removed[] = $v['id'];
    }
}

echo "Total vehicles before cleaning: " . count($json['vehicles']) . "\n";
echo "Vehicles with INVALID reg_no: " . $invalidRegCount . "\n";
echo "Duplicate URL records removed: " . (count($removed) - $invalidRegCount) . "\n";
echo "Total removed: " . count($removed) . "\n";
echo "Vehicles kept: " . count($cleanedVehicles) . "\n\n";

if (count($removed) > 0) {
    echo "Removed vehicle IDs: " . implode(', ', $removed) . "\n\n";
}

// Recalculate statistics
$totalImages = 0;
$withImages = 0;
foreach ($cleanedVehicles as $v) {
    $count = (int)($v['images']['count'] ?? 0);
    $totalImages += $count;
    if ($count > 0) {
        $withImages++;
    }
}

$newStats = [
    'total_vehicles' => count($cleanedVehicles),
    'with_color' => count(array_filter($cleanedVehicles, fn($v) => !empty($v['color']))),
    'with_doors' => count(array_filter($cleanedVehicles, fn($v) => !empty($v['doors']))),
    'with_transmission' => count(array_filter($cleanedVehicles, fn($v) => !empty($v['transmission']))),
    'with_fuel_type' => count(array_filter($cleanedVehicles, fn($v) => !empty($v['fuel_type']))),
    'with_body_style' => count(array_filter($cleanedVehicles, fn($v) => !empty($v['body_style']))),
    'with_images' => $withImages,
    'total_images' => $totalImages
];

// Update JSON
$json['count'] = count($cleanedVehicles);
$json['statistics'] = $newStats;
$json['vehicles'] = array_values($cleanedVehicles);  // Re-index array

// Save cleaned JSON
file_put_contents('data/vehicles.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "✓ Cleaned JSON saved!\n\n";
echo "NEW STATISTICS:\n";
echo "  Total vehicles: {$newStats['total_vehicles']}\n";
echo "  With images: {$newStats['with_images']}\n";
echo "  Total images: {$newStats['total_images']}\n";
echo "  With color: {$newStats['with_color']}\n";
echo "  With transmission: {$newStats['with_transmission']}\n";
echo "\n═══════════════════════════════════════════════════════\n";
?>
