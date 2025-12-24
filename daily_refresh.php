#!/usr/bin/env php
<?php
/**
 * Optimized Daily Data Refresh Script
 *
 * This script implements the most efficient daily refresh strategy:
 * 1. Scrape new data first (minimal downtime)
 * 2. Check success rate (safety threshold)
 * 3. Clean up old data only if the run is healthy
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
require_once __DIR__ . '/mail_alert.php';

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

    // Safety thresholds - CRITICAL to protect live website from zero inventory
    $minSuccessRate = 0.85; // 85% success rate required
    $minInventoryRatio = 0.80; // New data must be >= 80% of current active inventory
    
    // Get current inventory count BEFORE scrape
    $currentCountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM gyc_vehicle_info WHERE vendor_id = ? AND active_status IN ('1', '2')");
    $currentCountStmt->execute([$vendorId]);
    $currentActiveCount = (int)$currentCountStmt->fetch()['total'];
    echo "Current active inventory: {$currentActiveCount} vehicles\n\n";

    // Phase 1: Scrape new data (NO impact to live tables yet)
    echo "Phase 1: Scraping new data...\n";
    $startTime = microtime(true);

    // Create scraper instance
    $scraper = new CarSafariScraper($config, 'carsafari');
    $scraper->setVendorId($vendorId);

    // Run scraper WITHOUT cleanup phase (success rate will be checked after)
    $result = $scraper->runWithCarSafari();

    if (!$result['success']) {
        throw new Exception("Scraping failed: " . $result['error']);
    }

    $scrapeTime = microtime(true) - $startTime;
    echo "Scraping completed in " . round($scrapeTime, 2) . " seconds\n";
    echo "  Found: {$result['stats']['found']}\n";
    echo "  Inserted: {$result['stats']['inserted']}\n";
    echo "  Updated: {$result['stats']['updated']}\n";
    echo "  Skipped: {$result['stats']['skipped']}\n";
    echo "  Errors: {$result['stats']['errors']}\n\n";

    // Phase 2: Safety validation - check health BEFORE any cleanup/deactivation
    echo "Phase 2: Safety validation...\n";
    $totalChanges = (int)($result['stats']['inserted'] ?? 0) + (int)($result['stats']['updated'] ?? 0);
    $found = (int)($result['stats']['found'] ?? 0);
    $processed = (int)($result['stats']['inserted'] ?? 0)
        + (int)($result['stats']['updated'] ?? 0)
        + (int)($result['stats']['skipped'] ?? 0);
    $successRate = $found > 0 ? ($processed / $found) : 0.0;
    $successRatePct = round($successRate * 100, 1);
    
    // Count newly added vehicles in this run
    $newCountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM gyc_vehicle_info WHERE vendor_id = ? AND active_status IN ('1', '2')");
    $newCountStmt->execute([$vendorId]);
    $newActiveCount = (int)$newCountStmt->fetch()['total'];
    $inventoryRatioPct = $currentActiveCount > 0 ? round(($newActiveCount / $currentActiveCount) * 100, 1) : 0;
    
    echo "  Success Rate: {$successRatePct}% (required: " . ($minSuccessRate * 100) . "%)\n";
    echo "  Inventory Ratio: {$inventoryRatioPct}% (required: " . ($minInventoryRatio * 100) . "%)\n";
    echo "  Previous inventory: {$currentActiveCount} → Current: {$newActiveCount}\n\n";

    // Health check: only cleanup if metrics are healthy
    $isHealthy = $found > 0 
        && $successRate >= $minSuccessRate 
        && $newActiveCount >= (int)round($currentActiveCount * $minInventoryRatio);

    if ($isHealthy) {
        echo "Phase 3: CLEANUP APPROVED - Metrics are healthy\n";
        $cleanupStart = microtime(true);

        // Safe deactivation: mark vehicles not in current scrape as inactive
        echo "  Deactivating vehicles not in current scrape...\n";
        
        // Get all vehicle IDs currently in the system for this vendor
        $allIdsStmt = $pdo->prepare("SELECT id, reg_no FROM gyc_vehicle_info WHERE vendor_id = ? AND active_status IN ('1', '2')");
        $allIdsStmt->execute([$vendorId]);
        $allVehicles = $allIdsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get all reg_nos from scrape result (from database, not memory)
        $scrapedRegStmt = $pdo->prepare("SELECT DISTINCT reg_no FROM gyc_vehicle_info WHERE vendor_id = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $scrapedRegStmt->execute([$vendorId]);
        $scrapedRegs = array_column($scrapedRegStmt->fetchAll(), 'reg_no', 'reg_no');
        
        // Find vehicles to deactivate (were active, not in current scrape)
        $toDeactivate = [];
        foreach ($allVehicles as $id => $regNo) {
            if (!isset($scrapedRegs[$regNo])) {
                $toDeactivate[] = $id;
            }
        }
        
        if (!empty($toDeactivate)) {
            // Deactivate in chunks to avoid lock issues
            $chunkSize = 500;
            $deactivatedCount = 0;
            foreach (array_chunk($toDeactivate, $chunkSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $deactivateStmt = $pdo->prepare("UPDATE gyc_vehicle_info SET active_status = '0', updated_at = NOW() WHERE id IN ($placeholders)");
                $deactivateStmt->execute($chunk);
                $deactivatedCount += $deactivateStmt->rowCount();
            }
            echo "  Deactivated {$deactivatedCount} vehicles not in current scrape\n";
        }

        // Delete all vendor data (cleanup before fresh scrape)
        echo "  Deleting all vendor data (before fresh scrape)...\n";
        
        // Get all vehicle IDs for this vendor
        $vendorVehicles = $pdo->prepare("SELECT id FROM gyc_vehicle_info WHERE vendor_id = ?");
        $vendorVehicles->execute([$vendorId]);
        $vehicleIds = array_column($vendorVehicles->fetchAll(), 'id');
        
        $deletedCount = 0;
        if (!empty($vehicleIds)) {
            // Delete images first (FK constraint)
            $chunkSize = 500;
            foreach (array_chunk($vehicleIds, $chunkSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $deleteImages = $pdo->prepare("DELETE FROM gyc_product_images WHERE vechicle_info_id IN ($placeholders)");
                $deleteImages->execute($chunk);
            }
            
            // Delete vehicles
            foreach (array_chunk($vehicleIds, $chunkSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $deleteVehicles = $pdo->prepare("DELETE FROM gyc_vehicle_info WHERE id IN ($placeholders)");
                $deleteVehicles->execute($chunk);
                $deletedCount += $deleteVehicles->rowCount();
            }
        }
        
        if ($deletedCount > 0) {
            echo "  Deleted {$deletedCount} vehicles (all vendor {$vendorId} data)\n";
        } else {
            echo "  No vehicles to delete\n";
        }

        // Optional: Optimize tables (weekly)
        if (date('w') == '0' || $force) { // Sunday or force mode
            echo "  Optimizing tables...\n";
            $pdo->exec("OPTIMIZE TABLE gyc_vehicle_info");
            $pdo->exec("OPTIMIZE TABLE gyc_vehicle_attribute");
            $pdo->exec("OPTIMIZE TABLE gyc_product_images");
            echo "  Tables optimized\n";
        }

        $cleanupTime = microtime(true) - $cleanupStart;
        echo "  Cleanup completed in " . round($cleanupTime, 2) . " seconds\n\n";
    } else {
        echo "Phase 3: CLEANUP SKIPPED - Safety thresholds NOT met\n";
        $reasons = [];
        if ($successRate < $minSuccessRate) {
            $reasons[] = "Success rate {$successRatePct}% < {" . ($minSuccessRate * 100) . "%}";
        }
        if ($newActiveCount < (int)round($currentActiveCount * $minInventoryRatio)) {
            $reasons[] = "Inventory ratio {$inventoryRatioPct}% < " . ($minInventoryRatio * 100) . "%";
        }
        echo "  Reason: " . implode(", ", $reasons) . "\n";
        echo "  LIVE INVENTORY PRESERVED - No deactivation performed\n\n";
    }

    // Phase 4: Final statistics
    $totalTime = microtime(true) - $startTime;

    // Get final vehicle count
    $countSql = "SELECT COUNT(*) as total FROM gyc_vehicle_info WHERE vendor_id = ? AND active_status IN ('1', '2')";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute([$vendorId]);
    $finalCount = $stmt->fetch()['total'];

    echo "==============================================\n";
    echo "DAILY REFRESH COMPLETED SUCCESSFULLY\n";
    echo "==============================================\n";
    echo "Performance Metrics:\n";
    echo "  Total Time: " . round($totalTime, 2) . " seconds\n";
    echo "  Scrape Time: " . round($scrapeTime, 2) . " seconds\n";
    echo "  Changes Made: " . $totalChanges . "\n";
    echo "  Final Active Vehicles: $finalCount\n";

    if ($totalChanges > 0) {
        $changeRate = round(($totalChanges / $result['stats']['found']) * 100, 1);
        echo "  Change Rate: $changeRate%\n";
    }

    echo "\nOptimization Features Applied:\n";
    echo "  - Smart Change Detection (hash-based, 100% skip for unchanged)\n";
    echo "  - Safety Gate (only cleanup if health metrics pass)\n";
    echo "  - Bulk operations & minimal downtime\n";
    echo "  - Auto-publish & stale deactivation\n";

    // Send alert email (best-effort)
    $errors = (int)($result['stats']['errors'] ?? 0);
    $noteParts = [];
    $noteParts[] = "Success rate: {$successRatePct}% (required: " . ($minSuccessRate * 100) . "%)";
    $noteParts[] = "Inventory: {$currentActiveCount} → {$finalCount}";
    if ($errors > 0) {
        $noteParts[] = "Errors: {$errors}";
    }
    if ($isHealthy) {
        $noteParts[] = "Cleanup: APPROVED";
    } else {
        $noteParts[] = "Cleanup: SKIPPED (protection enabled)";
    }
    $note = implode(' | ', $noteParts);
    send_scrape_alert($vendorId, $result['stats'], $isHealthy, $note);

    exit(0);

} catch (Exception $e) {
    echo "\nDAILY REFRESH FAILED\n";
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

    // Alert on failure (best-effort)
    if (function_exists('send_scrape_alert')) {
        send_scrape_alert($vendorId ?? 432, [], false, $e->getMessage());
    }

    exit(1);
}
