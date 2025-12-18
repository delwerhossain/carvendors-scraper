#!/usr/bin/env php
<?php
/**
 * Color Mapping Tester - Verify color_id resolution works correctly
 * 
 * Usage:
 *   php scripts/test-color-mapping.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../CarScraper.php';
require_once __DIR__ . '/../CarSafariScraper.php';

// Test colors (mixed case, variants, finishes, combos)
$testColors = [
    // Exact matches
    ['input' => 'Red', 'expected' => 18],
    ['input' => 'red', 'expected' => 18],
    ['input' => 'RED', 'expected' => 18],
    
    // Variants
    ['input' => 'Crimson', 'expected' => 18],
    ['input' => 'Dark Red', 'expected' => 18],
    ['input' => 'Fire Red', 'expected' => 18],
    
    // With finishes
    ['input' => 'Pearl White', 'expected' => 20],
    ['input' => 'Pearl Black', 'expected' => 2],
    ['input' => 'Metallic Silver', 'expected' => 21],
    ['input' => 'Matte Black', 'expected' => 2],
    
    // Combos
    ['input' => 'Black/Red', 'expected' => 2],
    ['input' => 'Silver, Black', 'expected' => 21],
    ['input' => 'Red | White', 'expected' => 18],
    
    // Edge cases
    ['input' => 'None', 'expected' => 19],
    ['input' => 'White', 'expected' => 20],
    ['input' => 'Navy Blue', 'expected' => 14],
    ['input' => 'Light Grey', 'expected' => 9],
    ['input' => 'Burgundy', 'expected' => 6],
    ['input' => 'Bronze', 'expected' => 4],
    ['input' => 'Gold', 'expected' => 7],
];

// Initialize scraper (needs DB connection)
try {
    $scraper = new CarSafariScraper($config);
    $db = $scraper->getDatabase();
    
    echo "=== Color Mapping Test Suite ===\n";
    echo "Testing: " . count($testColors) . " color variants\n\n";
    
    $passed = 0;
    $failed = 0;
    $results = [];
    
    foreach ($testColors as $test) {
        $input = $test['input'];
        $expected = $test['expected'];
        
        // Use reflection to call private method
        $reflection = new ReflectionClass($scraper);
        $method = $reflection->getMethod('resolveColorId');
        $method->setAccessible(true);
        $actual = $method->invoke($scraper, $input);
        
        $status = ($actual === $expected) ? '✓ PASS' : '✗ FAIL';
        $passed += ($actual === $expected) ? 1 : 0;
        $failed += ($actual === $expected) ? 0 : 1;
        
        $results[] = [
            'input' => $input,
            'expected' => $expected,
            'actual' => $actual,
            'status' => $status
        ];
    }
    
    // Display results
    echo str_pad('Input', 25) . str_pad('Expected', 12) . str_pad('Actual', 12) . "Status\n";
    echo str_repeat('-', 65) . "\n";
    
    foreach ($results as $r) {
        echo str_pad($r['input'], 25) 
           . str_pad($r['expected'] ?? 'NULL', 12) 
           . str_pad($r['actual'] ?? 'NULL', 12) 
           . $r['status'] . "\n";
    }
    
    echo "\n" . str_repeat('-', 65) . "\n";
    echo "PASSED: $passed / " . count($testColors) . "\n";
    echo "FAILED: $failed / " . count($testColors) . "\n";
    
    if ($failed === 0) {
        echo "\n✓ All tests passed! Color mapping is working correctly.\n";
        exit(0);
    } else {
        echo "\n✗ Some tests failed. Check CarSafariScraper.php::resolveColorId() method.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Make sure database is running and config.php is correct.\n";
    exit(1);
}
?>
