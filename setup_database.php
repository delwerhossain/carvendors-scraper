<?php
/**
 * Database Setup Script
 * Creates all required tables for the statistics system
 */

$config = require_once 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Database connected successfully.\n";

    // Read and execute CREATE_STATISTICS_TABLES.sql
    $sqlFile = 'CREATE_STATISTICS_TABLES.sql';
    if (!file_exists($sqlFile)) {
        echo "ERROR: Statistics SQL file not found: $sqlFile\n";
        exit(1);
    }

    $sql = file_get_contents($sqlFile);
    $statements = explode(';', $sql);

    echo "Creating statistics tables...\n";

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "  ✓ Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (PDOException $e) {
                // Skip if table already exists
                if (strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate table') !== false) {
                    echo "  (Table already exists, skipping)\n";
                } else {
                    echo "  ⚠ Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    echo "\nStatistics tables setup completed successfully!\n";
    echo "You can now run the scraper with statistics enabled.\n";

} catch (PDOException $e) {
    echo "Database connection error: " . $e->getMessage() . "\n";
    exit(1);
}