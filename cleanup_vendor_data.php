#!/usr/bin/env php
<?php
/**
 * Vendor Data Cleanup Script
 *
 * Safely removes all data for a specific vendor in the correct order
 * to maintain database integrity.
 *
 * Usage:
 *   php cleanup_vendor_data.php --vendor=432
 *   php cleanup_vendor_data.php --vendor=432 --confirm
 *   php cleanup_vendor_data.php --vendor=432 --dry-run
 */

// CLI check
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Parse command line
$options = getopt('', ['vendor:', 'confirm', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Vendor Data Cleanup\n";
    echo "===================\n\n";
    echo "Usage: php cleanup_vendor_data.php [options]\n\n";
    echo "Required:\n";
    echo "  --vendor=ID   Vendor ID to clean up\n\n";
    echo "Options:\n";
    echo "  --confirm     Actually perform the deletion (REQUIRED for real deletion)\n";
    echo "  --dry-run     Show what would be deleted without actually deleting\n";
    echo "  --help        Show this help message\n\n";
    echo "⚠️  WARNING: This permanently deletes all data for the specified vendor!\n\n";
    exit(0);
}

if (!isset($options['vendor'])) {
    echo "❌ Error: --vendor=ID is required\n";
    echo "Use --help for usage information\n";
    exit(1);
}

$vendorId = (int)$options['vendor'];
$confirm = isset($options['confirm']);
$dryRun = isset($options['dry-run']);

if (!$confirm && !$dryRun) {
    echo "❌ Error: Either --confirm or --dry-run is required\n";
    echo "Use --dry-run to see what would be deleted\n";
    echo "Use --confirm to actually perform the deletion\n";
    echo "Use --help for usage information\n";
    exit(1);
}

if ($confirm && $dryRun) {
    echo "❌ Error: Cannot use --confirm and --dry-run together\n";
    exit(1);
}

echo "==============================================\n";
echo "Vendor Data Cleanup - " . date('Y-m-d H:i:s') . "\n";
echo "Vendor ID: $vendorId\n";
echo "Mode: " . ($confirm ? '🔴 CONFIRMED DELETION' : '🔵 DRY RUN') . "\n";
echo "==============================================\n\n";

try {
    // Load config and connect
    $config = require __DIR__ . '/config.php';

    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Get vendor statistics
    echo "📊 Current Statistics for Vendor $vendorId:\n";

    $stats = [
        'vehicle_info' => 0,
        'vehicle_attribute' => 0,
        'product_images' => 0,
        'scraper_statistics' => 0
    ];

    // Count vehicles
    $sql = "SELECT COUNT(*) as count FROM gyc_vehicle_info WHERE vendor_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$vendorId]);
    $stats['vehicle_info'] = $stmt->fetch()['count'];

    // Count attributes used by this vendor's vehicles
    $sql = "SELECT COUNT(DISTINCT v.attr_id) as count
            FROM gyc_vehicle_info v
            WHERE v.vendor_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$vendorId]);
    $stats['vehicle_attribute'] = $stmt->fetch()['count'];

    // Count images
    $sql = "SELECT COUNT(*) as count FROM gyc_product_images pi
            JOIN gyc_vehicle_info v ON pi.vechicle_info_id = v.id
            WHERE v.vendor_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$vendorId]);
    $stats['product_images'] = $stmt->fetch()['count'];

    // Count scraper statistics
    $sql = "SELECT COUNT(*) as count FROM scraper_statistics WHERE vendor_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$vendorId]);
    $stats['scraper_statistics'] = $stmt->fetch()['count'];

    echo "  Vehicles (gyc_vehicle_info): {$stats['vehicle_info']}\n";
    echo "  Attributes (gyc_vehicle_attribute): {$stats['vehicle_attribute']}\n";
    echo "  Images (gyc_product_images): {$stats['product_images']}\n";
    echo "  Statistics (scraper_statistics): {$stats['scraper_statistics']}\n\n";

    if ($dryRun) {
        echo "🔵 DRY RUN - No data will be deleted\n";
        echo "The following would be performed in order:\n\n";
    }

    // Deletion order (most dependent first)
    $operations = [
        [
            'name' => 'Product Images',
            'table' => 'gyc_product_images',
            'sql' => "DELETE pi FROM gyc_product_images pi
                    JOIN gyc_vehicle_info v ON pi.vechicle_info_id = v.id
                    WHERE v.vendor_id = ?"
        ],
        [
            'name' => 'Vehicle Info',
            'table' => 'gyc_vehicle_info',
            'sql' => "DELETE FROM gyc_vehicle_info WHERE vendor_id = ?"
        ],
        [
            'name' => 'Scraper Statistics',
            'table' => 'scraper_statistics',
            'sql' => "DELETE FROM scraper_statistics WHERE vendor_id = ?"
        ]
    ];

    $totalDeleted = 0;

    foreach ($operations as $operation) {
        echo "Processing {$operation['name']}...\n";

        if ($dryRun) {
            echo "  Would execute: {$operation['sql']}\n";
            echo "  Would delete approximately: ~" . $stats[strtolower(str_replace(' ', '_', $operation['name']))] . " records\n";
        } else {
            $stmt = $pdo->prepare($operation['sql']);
            $stmt->execute([$vendorId]);
            $deleted = $stmt->rowCount();
            $totalDeleted += $deleted;

            echo "  ✓ Deleted $deleted records\n";
        }
        echo "\n";
    }

    // Note about orphaned attributes
    echo "⚠️  Note: Vehicle attributes may remain if used by other vendors\n";
    echo "    Run php cleanup_orphaned_attributes.php to remove unused attributes\n\n";

    echo "==============================================\n";
    if ($dryRun) {
        echo "🔵 DRY RUN COMPLETED\n";
        echo "No data was actually deleted\n";
        echo "Run with --confirm to perform the actual deletion\n";
    } else {
        echo "✅ VENDOR DATA CLEANUP COMPLETED\n";
        echo "Total records deleted: $totalDeleted\n";
    }
    echo "==============================================\n";

    exit(0);

} catch (Exception $e) {
    echo "\n❌ CLEANUP FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "No data was modified\n";
    exit(1);
}