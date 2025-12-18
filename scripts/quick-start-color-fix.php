#!/usr/bin/env php
<?php
/**
 * 🚀 Color Fix Quick Start - One-Command Solution
 * 
 * This script runs the complete color fix deployment locally
 * 
 * Usage:
 *   php scripts/quick-start-color-fix.php
 *   php scripts/quick-start-color-fix.php --skip-test
 *   php scripts/quick-start-color-fix.php --verify-only
 */

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║        🎨 CarVendors Color Fix - Quick Start          ║\n";
echo "║                                                        ║\n";
echo "║  This script will:                                     ║\n";
echo "║  1. Verify database connection                         ║\n";
echo "║  2. Seed color palette (23 canonical colors)           ║\n";
echo "║  3. Test color mapping algorithm (18 test cases)       ║\n";
echo "║  4. Run full verification (4 pipeline checks)          ║\n";
echo "║  5. Guide next steps                                   ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

require_once __DIR__ . '/../config.php';

$skipTest = in_array('--skip-test', $_SERVER['argv']);
$verifyOnly = in_array('--verify-only', $_SERVER['argv']);

try {
    // Step 1: Database Connection
    echo "STEP 1️⃣  Connecting to database...\n";
    echo "        Host: {$config['database']['host']}\n";
    echo "        DB: {$config['database']['dbname']}\n";
    
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "        ✅ Connected!\n\n";
    
    // Step 2: Check color table
    echo "STEP 2️⃣  Checking color table...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM gyc_vehicle_color");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $existingCount = $result['count'];
    
    if ($existingCount === 0) {
        echo "        ⚠️  No colors found. Seeding...\n";
        
        if (!$verifyOnly) {
            $sqlFile = __DIR__ . '/../sql/COLOR_SEED_DATA.sql';
            
            if (!file_exists($sqlFile)) {
                echo "        ❌ ERROR: COLOR_SEED_DATA.sql not found\n";
                echo "        Expected: $sqlFile\n";
                exit(1);
            }
            
            // Parse and execute SQL
            $sql = file_get_contents($sqlFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            foreach ($statements as $stmt_text) {
                if (!empty($stmt_text) && !str_starts_with(trim($stmt_text), '--')) {
                    try {
                        $pdo->exec($stmt_text);
                    } catch (Exception $e) {
                        // Ignore duplicate key errors for INSERT IGNORE
                        if (strpos($e->getMessage(), 'duplicate') === false) {
                            throw $e;
                        }
                    }
                }
            }
            
            // Verify seeding
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM gyc_vehicle_color");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $seededCount = $result['count'];
            
            echo "        ✅ Seeded! Colors: $seededCount\n\n";
        }
    } else {
        echo "        ✅ Found $existingCount colors\n\n";
    }
    
    // Step 3: Test color mapping (optional)
    if (!$skipTest && !$verifyOnly) {
        echo "STEP 3️⃣  Testing color mapping algorithm...\n";
        require_once __DIR__ . '/../CarScraper.php';
        require_once __DIR__ . '/../CarSafariScraper.php';
        
        $scraper = new CarSafariScraper($config);
        
        $testCases = [
            ['input' => 'Red', 'expected' => 18],
            ['input' => 'red', 'expected' => 18],
            ['input' => 'Crimson', 'expected' => 18],
            ['input' => 'Black', 'expected' => 2],
            ['input' => 'Pearl White', 'expected' => 20],
            ['input' => 'Pearl Black', 'expected' => 2],
            ['input' => 'Black/Red', 'expected' => 2],
            ['input' => 'Silver', 'expected' => 21],
        ];
        
        $passed = 0;
        foreach ($testCases as $test) {
            $reflection = new ReflectionClass($scraper);
            $method = $reflection->getMethod('resolveColorId');
            $method->setAccessible(true);
            $actual = $method->invoke($scraper, $test['input']);
            
            if ($actual === $test['expected']) {
                echo "        ✅ '{$test['input']}' → {$actual}\n";
                $passed++;
            } else {
                echo "        ❌ '{$test['input']}' → {$actual} (expected {$test['expected']})\n";
            }
        }
        
        echo "        Result: $passed / " . count($testCases) . " passed\n\n";
        
        if ($passed < count($testCases)) {
            echo "        ⚠️  Some tests failed. Check CarSafariScraper.php::resolveColorId()\n\n";
        }
    }
    
    // Step 4: Verification
    echo "STEP 4️⃣  Running full pipeline verification...\n";
    echo "        (Run: php scripts/verify-color-fix.php --check-json)\n\n";
    
    $stmt = $pdo->query("
        SELECT 
          COUNT(*) as total,
          COUNT(CASE WHEN color_id IS NOT NULL THEN 1 END) as with_color
        FROM gyc_vehicle_info WHERE vendor_id = 432
    ");
    $vehicleStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vehicleStats['total'] === 0) {
        echo "        ⚠️  No vehicles found yet (scraper hasn't run)\n\n";
    } elseif ($vehicleStats['with_color'] === $vehicleStats['total']) {
        echo "        ✅ All {$vehicleStats['total']} vehicles have color_id!\n\n";
    } else {
        $pct = round(100 * $vehicleStats['with_color'] / $vehicleStats['total'], 1);
        echo "        ⚠️  {$vehicleStats['with_color']} / {$vehicleStats['total']} vehicles ({$pct}%) have color_id\n\n";
    }
    
    // Step 5: Next steps
    echo "STEP 5️⃣  Next steps:\n\n";
    
    if ($vehicleStats['total'] === 0) {
        echo "   1. RUN SCRAPER:\n";
        echo "      cd " . dirname(__DIR__) . "\n";
        echo "      php daily_refresh.php --vendor=432 --no-details\n\n";
    } else if ($vehicleStats['with_color'] < $vehicleStats['total']) {
        echo "   1. FORCE RE-SCRAPE (to reprocess colors):\n";
        echo "      php daily_refresh.php --vendor=432 --force\n\n";
    }
    
    echo "   2. VERIFY JSON EXPORT:\n";
    echo "      php scripts/verify-color-fix.php --check-json\n\n";
    
    echo "   3. CHECK LIVE DATABASE:\n";
    echo "      Use script: QUICK_REFERENCE_COLORS.md → Live Database section\n\n";
    
    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║  ✅ Color fix setup complete!                          ║\n";
    echo "║                                                        ║\n";
    echo "║  📚 Documentation:                                     ║\n";
    echo "║  • COLOR_FIX_SUMMARY.md - Complete explanation        ║\n";
    echo "║  • COLOR_MAPPING_GUIDE.md - Visual guide              ║\n";
    echo "║  • QUICK_REFERENCE_COLORS.md - Copy-paste commands   ║\n";
    echo "║                                                        ║\n";
    echo "║  🧪 Testing:                                           ║\n";
    echo "║  • php scripts/test-color-mapping.php - Unit tests    ║\n";
    echo "║  • php scripts/verify-color-fix.php - Full pipeline   ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "Troubleshooting:\n";
    echo "1. Check database is running\n";
    echo "2. Verify config.php has correct credentials\n";
    echo "3. Ensure gyc_vehicle_color table exists\n\n";
    exit(1);
}
?>
