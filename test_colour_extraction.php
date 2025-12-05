<?php
/**
 * Test script for vehicle details extraction
 * Tests colour, engine_size, drive_system extraction from real HTML
 */

require_once __DIR__ . '/CarScraper.php';

// Create a test class to access protected methods
class TestScraper extends CarScraper {
    public function testExtractDetails(string $html): array {
        return $this->extractVehicleDetails($html);
    }
}

// Sample HTML from systonautosltd.co.uk vehicle detail page
$sampleHtml = <<<'HTML'
<div class="vehicle-details">
    <ul class="vd-details-list">
        <li class="clearfix"><span class="vd-detail-name">Mileage</span><span class="vd-detail-value">75,000</span></li>
        <li class="clearfix"><span class="vd-detail-name">Colour</span><span class="vd-detail-value">Silver</span></li>
        <li class="clearfix"><span class="vd-detail-name">Transmission</span><span class="vd-detail-value">Manual</span></li>
        <li class="clearfix"><span class="vd-detail-name">Fuel Type</span><span class="vd-detail-value">Diesel</span></li>
        <li class="clearfix"><span class="vd-detail-name">Body Style</span><span class="vd-detail-value">Hatchback</span></li>
        <li class="clearfix"><span class="vd-detail-name">First Registration Date</span><span class="vd-detail-value">28/12/2016</span></li>
        <li class="clearfix"><span class="vd-detail-name">Engine Size</span><span class="vd-detail-value">1,969</span></li>
    </ul>
</div>
HTML;

// CarCheck style HTML
$carCheckHtml = <<<'HTML'
<table class="table table-striped table-condensed">
    <tbody>
        <tr>
            <th style="width:58%;border-top:0;">Make</th>
            <td>VOLVO</td>
        </tr>
        <tr>
            <th>Model</th>
            <td>V40 D4 R-DESIGN LUX NAV</td>
        </tr>
        <tr>
            <th>Colour</th>
            <td>Silver</td>
        </tr>
        <tr>
            <th>Year of manufacture</th>
            <td>2016</td>
        </tr>
        <tr>
            <th>Gearbox</th>
            <td>6 speed Manual</td>
        </tr>
    </tbody>
</table>
HTML;

// Generic text style
$genericHtml = <<<'HTML'
<div>
    Mileage75,000
    ColourSilver
    TransmissionManual
    Fuel TypeDiesel
    Body StyleHatchback
</div>
HTML;

echo "=== VEHICLE DETAILS EXTRACTION TEST ===\n\n";

// Load config
$config = include __DIR__ . '/config.php';

try {
    $scraper = new TestScraper($config);
    
    // Test 1: Systonautos structured HTML
    echo "TEST 1: Systonautos Structured HTML\n";
    echo str_repeat("-", 60) . "\n";
    $result1 = $scraper->testExtractDetails($sampleHtml);
    foreach ($result1 as $key => $value) {
        echo "  $key: " . ($value ?? 'NULL') . "\n";
    }
    echo "\n";
    
    // Test 2: CarCheck table HTML
    echo "TEST 2: CarCheck Table HTML\n";
    echo str_repeat("-", 60) . "\n";
    $result2 = $scraper->testExtractDetails($carCheckHtml);
    foreach ($result2 as $key => $value) {
        echo "  $key: " . ($value ?? 'NULL') . "\n";
    }
    echo "\n";
    
    // Test 3: Generic text HTML
    echo "TEST 3: Generic Text HTML\n";
    echo str_repeat("-", 60) . "\n";
    $result3 = $scraper->testExtractDetails($genericHtml);
    foreach ($result3 as $key => $value) {
        echo "  $key: " . ($value ?? 'NULL') . "\n";
    }
    echo "\n";
    
    // Summary
    echo "=== SUMMARY ===\n";
    $tests = [
        ['name' => 'Systonautos', 'result' => $result1, 'expected' => ['colour' => 'Silver', 'engine_size' => 1969]],
        ['name' => 'CarCheck', 'result' => $result2, 'expected' => ['colour' => 'Silver', 'transmission' => 'Manual']],
        ['name' => 'Generic', 'result' => $result3, 'expected' => ['colour' => 'Silver']],
    ];
    
    $passed = 0;
    $failed = 0;
    
    foreach ($tests as $test) {
        echo "\n{$test['name']}:\n";
        foreach ($test['expected'] as $key => $expected) {
            $actual = $test['result'][$key] ?? null;
            if ($actual == $expected) {
                echo "  ✓ $key: $actual\n";
                $passed++;
            } else {
                echo "  ✗ $key: expected '$expected', got '$actual'\n";
                $failed++;
            }
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "PASSED: $passed, FAILED: $failed\n";
    
    if ($failed === 0) {
        echo "\n🎉 ALL TESTS PASSED!\n";
    } else {
        echo "\n⚠️  SOME TESTS FAILED\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
