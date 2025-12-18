#!/usr/bin/env php
<?php
/**
 * Complete Color Pipeline Verification
 * 
 * Checks:
 * 1. Color seed data in database
 * 2. Color mapping algorithm
 * 3. Vehicle color_id population
 * 4. JSON export format
 * 
 * Usage:
 *   php scripts/verify-color-fix.php
 *   php scripts/verify-color-fix.php --check-json
 *   php scripts/verify-color-fix.php --full
 */

require_once __DIR__ . '/../config.php';

echo "=== Color Pipeline Verification ===\n\n";

try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check 1: Color seeding
    echo "1️⃣  Checking color seed data...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM gyc_vehicle_color");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $colorCount = $result['total'];
    
    if ($colorCount === 0) {
        echo "   ❌ ERROR: No colors seeded! Run: mysql -u root -p tst-car < sql/COLOR_SEED_DATA.sql\n\n";
    } elseif ($colorCount < 23) {
        echo "   ⚠️  WARNING: Only $colorCount colors (expected 23+). Seed data incomplete.\n";
        echo "   Run: mysql -u root -p tst-car < sql/COLOR_SEED_DATA.sql\n\n";
    } else {
        echo "   ✅ PASS: $colorCount colors seeded\n";
        $stmt = $pdo->query("SELECT id, color_name FROM gyc_vehicle_color ORDER BY id LIMIT 5");
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "   Sample:\n";
        foreach ($colors as $color) {
            echo "     - ID {$color['id']}: {$color['color_name']}\n";
        }
        echo "\n";
    }
    
    // Check 2: Vehicles with color_id
    echo "2️⃣  Checking vehicle color_id population...\n";
    $stmt = $pdo->query("
        SELECT 
          COUNT(*) as total,
          COUNT(CASE WHEN color_id IS NOT NULL THEN 1 END) as with_color_id,
          COUNT(CASE WHEN color_id IS NULL THEN 1 END) as without_color_id
        FROM gyc_vehicle_info 
        WHERE vendor_id = 432
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['total'] === 0) {
        echo "   ⚠️  WARNING: No vehicles for vendor 432. Run scraper first.\n";
        echo "   Run: php daily_refresh.php --vendor=432 --no-details\n\n";
    } elseif ($result['without_color_id'] > 0) {
        $pct = round(100 * $result['without_color_id'] / $result['total'], 1);
        echo "   ⚠️  WARNING: {$result['without_color_id']} vehicles ({$pct}%) still have NULL color_id\n";
        echo "   These colors may need variants added or colors seeded:\n";
        $stmt = $pdo->query("
            SELECT DISTINCT color, COUNT(*) as count 
            FROM gyc_vehicle_info 
            WHERE vendor_id = 432 AND color_id IS NULL 
            GROUP BY color 
            ORDER BY count DESC 
            LIMIT 5
        ");
        $nullColors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($nullColors as $color) {
            echo "     - '{$color['color']}' ({$color['count']} vehicles)\n";
        }
        echo "\n";
    } else {
        $pct = round(100 * $result['with_color_id'] / $result['total'], 1);
        echo "   ✅ PASS: {$result['with_color_id']} / {$result['total']} vehicles ({$pct}%) have color_id\n\n";
    }
    
    // Check 3: Color distribution
    echo "3️⃣  Checking color distribution...\n";
    $stmt = $pdo->query("
        SELECT 
          v.color_id,
          c.color_name,
          COUNT(*) as count 
        FROM gyc_vehicle_info v
        LEFT JOIN gyc_vehicle_color c ON v.color_id = c.id
        WHERE v.vendor_id = 432 AND v.color_id IS NOT NULL
        GROUP BY v.color_id 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($distribution)) {
        echo "   ⚠️  No color_id mappings found yet.\n\n";
    } else {
        echo "   Color distribution (top 10):\n";
        foreach ($distribution as $dist) {
            $colorName = $dist['color_name'] ?? 'UNMAPPED';
            echo "     - ID {$dist['color_id']}: {$colorName} ({$dist['count']} vehicles)\n";
        }
        echo "\n";
    }
    
    // Check 4: JSON export
    if (in_array('--check-json', $_SERVER['argv']) || in_array('--full', $_SERVER['argv'])) {
        echo "4️⃣  Checking JSON export...\n";
        $jsonFile = __DIR__ . '/../data/vehicles.json';
        
        if (!file_exists($jsonFile)) {
            echo "   ⚠️  JSON file not found: $jsonFile\n\n";
        } else {
            $json = json_decode(file_get_contents($jsonFile), true);
            
            if (!isset($json['vehicles']) || empty($json['vehicles'])) {
                echo "   ⚠️  JSON has no vehicles\n\n";
            } else {
                $sample = $json['vehicles'][0];
                
                echo "   Sample vehicle:\n";
                echo "     Registration: {$sample['registration'] ?? 'N/A'}\n";
                echo "     Color: {$sample['color'] ?? 'N/A'}\n";
                echo "     Color ID: {$sample['color_id'] ?? 'NULL'} ❌\n";
                echo "     Manufacturer Color ID: {$sample['manufacturer_color_id'] ?? 'NULL'} ❌\n";
                
                if (is_null($sample['color_id']) || is_null($sample['manufacturer_color_id'])) {
                    echo "\n   ⚠️  color_id is NULL in JSON. Likely causes:\n";
                    echo "     1. Run scraper before color fix (re-run with --force)\n";
                    echo "     2. Color not in database seed\n";
                    echo "     3. Color variant not in mapping\n";
                } else {
                    echo "\n   ✅ PASS: color_id fields populated in JSON\n";
                }
                
                // Count nulls
                $nullCount = 0;
                foreach ($json['vehicles'] as $vehicle) {
                    if (is_null($vehicle['color_id']) || is_null($vehicle['manufacturer_color_id'])) {
                        $nullCount++;
                    }
                }
                
                $totalCount = count($json['vehicles']);
                $nullPct = round(100 * $nullCount / $totalCount, 1);
                
                if ($nullCount > 0) {
                    echo "   ⚠️  $nullCount / $totalCount vehicles ({$nullPct}%) have NULL color_id\n\n";
                } else {
                    echo "   ✅ All vehicles in JSON have color_id\n\n";
                }
            }
        }
    }
    
    // Summary & Next Steps
    echo "=== Verification Summary ===\n";
    
    if ($colorCount >= 23) {
        echo "✅ Colors are seeded\n";
    } else {
        echo "❌ Colors need to be seeded\n";
    }
    
    if ($result['total'] > 0 && $result['without_color_id'] === 0) {
        echo "✅ All vehicles have color_id\n";
    } else if ($result['total'] > 0) {
        echo "⚠️  Some vehicles missing color_id\n";
    } else {
        echo "⚠️  No vehicles found (scraper hasn't run)\n";
    }
    
    echo "\n=== Next Steps ===\n";
    
    if ($colorCount < 23) {
        echo "1. Seed colors:\n";
        echo "   mysql -u root -p tst-car < sql/COLOR_SEED_DATA.sql\n";
    }
    
    if ($result['total'] === 0) {
        echo "2. Run scraper:\n";
        echo "   php daily_refresh.php --vendor=432 --no-details\n";
    } else if ($result['without_color_id'] > 0) {
        echo "2. Force re-scrape (re-process all colors):\n";
        echo "   php daily_refresh.php --vendor=432 --force\n";
    }
    
    echo "3. Re-run verification:\n";
    echo "   php scripts/verify-color-fix.php --check-json\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Make sure database is running and config.php is correct.\n";
    exit(1);
}
?>
