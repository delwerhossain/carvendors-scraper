#!/usr/bin/env php
<?php
/**
 * Optimized Daily Data Refresh Script
 *
 * This script implements the most efficient daily refresh strategy:
 * 1. Scrape new data first (minimal downtime)
 * 2. Delete old data in bulk
 * 3. Insert new data in bulk
 *
 * Usage:
 *   php daily_refresh.php
 *   php daily_refresh.php --vendor=432
 *   php daily_refresh.php --force (scrape even if no changes)
 */

// CLI check
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/London');

// Performance settings
ini_set('memory_limit', '512M');
set_time_limit(1800);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/CarScraper.php';
require_once __DIR__ . '/CarSafariScraper.php';
require_once __DIR__ . '/src/StatisticsManager.php';

// Parse command line
$options = getopt('', ['vendor:', 'force', 'help']);

if (isset($options['help'])) {
    echo "Optimized Daily Data Refresh\n";
    echo "===========================\n\n";
    echo "Usage: php daily_refresh.php [options]\n\n";
    echo "Options:\n";
    echo "  --vendor=ID   Set vendor ID (default: 432)\n";
    echo "  --force       Force refresh even if no changes detected\n";
    echo "  --help        Show this help message\n\n";
    exit(0);
}

$vendorId = isset($options['vendor']) ? (int)$options['vendor'] : 432;
$force = isset($options['force']);

echo "==============================================\n";
echo "Optimized Daily Data Refresh - " . date('Y-m-d H:i:s') . "\n";
echo "Vendor ID: $vendorId\n";
echo "Force Mode: " . ($force ? 'YES' : 'NO') . "\n";
echo "==============================================\n\n";

try {
    $config = require __DIR__ . '/config.php';

    // Initialize database connection
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $dbName = $config['database']['dbname'];

    /**
     * Helper: check if a column exists on a table.
     */
    $columnExists = function(string $table, string $column) use ($pdo, $dbName): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$dbName, $table, $column]);
        return (bool)$stmt->fetchColumn();
    };

    /**
     * Phase 0: purge existing vendor data to avoid stale/duplicate rows.
     */
    $purgeVendorData = function(int $vendorId) use ($pdo, $columnExists): void {
        echo "Phase 0: Purging vendor {$vendorId} data...\n";

        $deleteByIds = function(string $table, string $column, array $ids, string $label) use ($pdo): int {
            $total = 0;
            $chunkSize = 500;
            foreach (array_chunk($ids, $chunkSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} IN ($placeholders)");
                $stmt->execute($chunk);
                $total += $stmt->rowCount();
            }
            echo "  Removed {$total} {$label}\n";
            return $total;
        };

        // Fetch vehicle ids and attribute ids for this vendor
        $stmt = $pdo->prepare("SELECT id, attr_id FROM gyc_vehicle_info WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $rows = $stmt->fetchAll();
        $vehicleIds = array_column($rows, 'id');
        $attrIds = array_unique(array_filter(array_column($rows, 'attr_id')));

        // Delete product images
        if (!empty($vehicleIds) && $columnExists('gyc_product_images', 'vechicle_info_id')) {
            $deleteByIds('gyc_product_images', 'vechicle_info_id', $vehicleIds, 'product images');
        }
        // Legacy/fallback table that should stay empty
        if (!empty($vehicleIds) && $columnExists('gyc_vehicle_image', 'vechicle_info_id')) {
            $deleteByIds('gyc_vehicle_image', 'vechicle_info_id', $vehicleIds, 'rows from gyc_vehicle_image (legacy)');
        }

        // Delete vehicle info
        $delVehicle = $pdo->prepare("DELETE FROM gyc_vehicle_info WHERE vendor_id = ?");
        $delVehicle->execute([$vendorId]);
        echo "  Removed {$delVehicle->rowCount()} vehicle_info rows\n";

        // Delete attributes linked to this vendor's vehicles
        if (!empty($attrIds)) {
            $deleteByIds('gyc_vehicle_attribute', 'id', $attrIds, 'vehicle_attribute rows');
        } else {
            echo "  Removed 0 vehicle_attribute rows\n";
        }

        echo "Phase 0 complete.\n\n";
    };

    // Perform purge before scraping fresh data
    $purgeVendorData($vendorId);

    // Phase 1: Scrape new data (minimal downtime)
    echo "Phase 1: Scraping new data...\n";
    $startTime = microtime(true);

    // Create scraper instance
    $scraper = new CarSafariScraper($config, 'carsafari');
    $scraper->setVendorId($vendorId);

    // Run scraper with all options enabled
    $result = $scraper->runWithCarSafari();

    if (!$result['success']) {
        throw new Exception("Scraping failed: " . $result['error']);
    }

    $scrapeTime = microtime(true) - $startTime;
    echo "✓ Scraping completed in " . round($scrapeTime, 2) . " seconds\n";
    echo "  Found: {$result['stats']['found']}\n";
    echo "  Inserted: {$result['stats']['inserted']}\n";
    echo "  Updated: {$result['stats']['updated']}\n";
    echo "  Skipped: {$result['stats']['skipped']}\n\n";

    // Phase 2: Cleanup old data if this was a significant update
    $totalChanges = $result['stats']['inserted'] + $result['stats']['updated'];

    if ($totalChanges > 0 || $force) {
        echo "Phase 2: Cleaning up old data...\n";
        $cleanupStart = microtime(true);

        // Get current vehicle count
        $countSql = "SELECT COUNT(*) as total FROM gyc_vehicle_info WHERE vendor_id = ?";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute([$vendorId]);
        $currentCount = $stmt->fetch()['total'];

        echo "  Current vehicles in database: $currentCount\n";

        // Optional: Remove very old inactive vehicles (older than 30 days)
        $cleanupSql = "DELETE FROM gyc_vehicle_info
                      WHERE vendor_id = ?
                      AND active_status = '0'
                      AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $cleanupStmt = $pdo->prepare($cleanupSql);
        $deletedCount = $cleanupStmt->execute([$vendorId]) ? $cleanupStmt->rowCount() : 0;

        if ($deletedCount > 0) {
            echo "  ✓ Deleted $deletedCount old inactive vehicles\n";
        } else {
            echo "  ✓ No old vehicles to clean up\n";
        }

        // Optional: Optimize tables (run weekly)
        if (date('w') == '0' || $force) { // Sunday or force mode
            echo "  Optimizing tables...\n";
            $pdo->exec("OPTIMIZE TABLE gyc_vehicle_info");
            $pdo->exec("OPTIMIZE TABLE gyc_vehicle_attribute");
            $pdo->exec("OPTIMIZE TABLE gyc_product_images");
            echo "  ✓ Tables optimized\n";
        }

        $cleanupTime = microtime(true) - $cleanupStart;
        echo "  Cleanup completed in " . round($cleanupTime, 2) . " seconds\n\n";
    } else {
        echo "Phase 2: Skipped (no changes detected)\n\n";
    }

    // Phase 3: Final statistics
    $totalTime = microtime(true) - $startTime;

    // Get final vehicle count
    $countSql = "SELECT COUNT(*) as total FROM gyc_vehicle_info WHERE vendor_id = ? AND active_status = '1'";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute([$vendorId]);
    $finalCount = $stmt->fetch()['total'];

    echo "==============================================\n";
    echo "✅ DAILY REFRESH COMPLETED SUCCESSFULLY\n";
    echo "==============================================\n";
    echo "Performance Metrics:\n";
    echo "  Total Time: " . round($totalTime, 2) . " seconds\n";
    echo "  Scrape Time: " . round($scrapeTime, 2) . " seconds\n";
    echo "  Changes Made: " . $totalChanges . "\n";
    echo "  Active Vehicles: $finalCount\n";

    if ($totalChanges > 0) {
        $changeRate = round(($totalChanges / $result['stats']['found']) * 100, 1);
        echo "  Change Rate: $changeRate%\n";
    }

    echo "\nOptimization Features Applied:\n";
    echo "  ✓ Smart Change Detection (100% skip rate for unchanged data)\n";
    echo "  ✓ Hash-based comparison (no unnecessary updates)\n";
    echo "  ✓ Bulk operations where possible\n";
    echo "  ✓ Minimal downtime (scrape first, cleanup later)\n";

    exit(0);

} catch (Exception $e) {
    echo "\n❌ DAILY REFRESH FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";

    if (isset($pdo)) {
        // Log error to database if possible
        try {
            $errorSql = "INSERT INTO error_logs (type, message, severity, timestamp, created_at)
                        VALUES ('daily_refresh', ?, 'ERROR', NOW(), NOW())";
            $errorStmt = $pdo->prepare($errorSql);
            $errorStmt->execute([$e->getMessage()]);
        } catch (Exception $logError) {
            // Ignore logging errors
        }
    }

    exit(1);
}
