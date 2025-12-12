#!/usr/bin/env php
<?php
/**
 * Orphaned Attributes Cleanup Script
 *
 * Removes vehicle attributes that are no longer linked to any vehicle info records.
 * Safe to run periodically to clean up the database.
 *
 * Usage:
 *   php cleanup_orphaned_attributes.php --dry-run
 *   php cleanup_orphaned_attributes.php --confirm
 */

// CLI check
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Parse command line
$options = getopt('', ['confirm', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Orphaned Attributes Cleanup\n";
    echo "===========================\n\n";
    echo "Usage: php cleanup_orphaned_attributes.php [options]\n\n";
    echo "Options:\n";
    echo "  --confirm     Actually perform the deletion\n";
    echo "  --dry-run     Show what would be deleted (default behavior)\n";
    echo "  --help        Show this help message\n\n";
    echo "This script removes vehicle attributes that are not linked to any vehicles.\n";
    echo "It's safe to run periodically to maintain database cleanliness.\n\n";
    exit(0);
}

$confirm = isset($options['confirm']);
$dryRun = isset($options['dry-run']) || !$confirm;

echo "==============================================\n";
echo "Orphaned Attributes Cleanup - " . date('Y-m-d H:i:s') . "\n";
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

    // Find orphaned attributes
    echo "🔍 Finding orphaned attributes...\n";

    $findSql = "SELECT a.id, a.model, a.year, a.created_at
                FROM gyc_vehicle_attribute a
                LEFT JOIN gyc_vehicle_info v ON a.id = v.attr_id
                WHERE v.id IS NULL";

    $stmt = $pdo->query($findSql);
    $orphanedAttributes = $stmt->fetchAll();

    $count = count($orphanedAttributes);
    echo "Found $count orphaned attributes\n\n";

    if ($count === 0) {
        echo "✅ No orphaned attributes found - database is clean!\n";
        exit(0);
    }

    if ($dryRun) {
        echo "🔵 DRY RUN - The following orphaned attributes would be deleted:\n\n";
        echo "ID\tModel\t\tYear\tCreated\n";
        echo "----------------------------------------\n";

        foreach ($orphanedAttributes as $attr) {
            $model = substr($attr['model'], 0, 30);
            echo "{$attr['id']}\t" . str_pad($model, 30) . "\t{$attr['year']}\t{$attr['created_at']}\n";
        }
        echo "\nRun with --confirm to delete these $count attributes\n";
    } else {
        echo "🔴 Deleting orphaned attributes...\n";

        // Delete in batches for safety
        $batchSize = 100;
        $deleted = 0;
        $batches = ceil($count / $batchSize);

        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * $batchSize;
            $batch = array_slice($orphanedAttributes, $offset, $batchSize);

            if (empty($batch)) break;

            // Create IN clause for this batch
            $ids = array_column($batch, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $deleteSql = "DELETE FROM gyc_vehicle_attribute WHERE id IN ($placeholders)";
            $deleteStmt = $pdo->prepare($deleteSql);
            $deleteStmt->execute($ids);

            $batchDeleted = $deleteStmt->rowCount();
            $deleted += $batchDeleted;

            echo "  Batch " . ($i + 1) . "/$batches: Deleted $batchDeleted attributes\n";
        }

        echo "\n✅ Cleanup completed!\n";
        echo "Total attributes deleted: $deleted\n";
    }

    echo "\nOptimization tip: Run 'OPTIMIZE TABLE gyc_vehicle_attribute' after large deletions\n";

} catch (Exception $e) {
    echo "\n❌ CLEANUP FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}