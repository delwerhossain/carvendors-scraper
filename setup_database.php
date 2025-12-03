<?php
$config = require 'config.php';

try {
    // Step 1: Connect without database selected
    echo "Step 1: Creating database if needed...\n";
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'],
        $config['database']['username'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $dbname = $config['database']['dbname'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database '$dbname' ready\n\n";

    // Step 2: Select the database
    $pdo->exec("USE `$dbname`");

    // Step 3: Load and execute the carsafari.sql file
    echo "Step 2: Loading database schema...\n";
    $sql = file_get_contents('carsafari.sql');

    // Remove comments and handle statements
    $lines = explode("\n", $sql);
    $statement = '';
    $executed = 0;

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and comments
        if (empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 2) === '/*') {
            continue;
        }

        $statement .= ' ' . $line;

        // Check if statement ends with semicolon
        if (substr(trim($line), -1) === ';') {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    // Table already exists, that's fine
                } else {
                    echo "  Warning: " . $e->getMessage() . "\n";
                }
            }
            $statement = '';
        }
    }

    echo "✓ Schema loaded: $executed statements executed\n\n";

    // Step 4: Apply the migration
    echo "Step 3: Applying migration (adding vehicle_url field)...\n";
    $migration_sql = file_get_contents('ALTER_DB_ADD_URL.sql');
    $migration_lines = explode("\n", $migration_sql);
    $migration_statement = '';

    foreach ($migration_lines as $line) {
        $line = trim($line);

        if (empty($line) || substr($line, 0, 2) === '--') {
            continue;
        }

        $migration_statement .= ' ' . $line;

        if (substr(trim($line), -1) === ';') {
            try {
                $pdo->exec($migration_statement);
                echo "  ✓ " . substr($migration_statement, 0, 60) . "...\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                    echo "  (Column already exists)\n";
                } else if (strpos($e->getMessage(), 'Duplicate key') !== false) {
                    echo "  (Index already exists)\n";
                } else {
                    throw $e;
                }
            }
            $migration_statement = '';
        }
    }

    echo "\n✓ Migration applied successfully!\n\n";

    // Step 5: Verify the column exists
    echo "Step 4: Verifying vehicle_url column...\n";
    $check = $pdo->query("DESCRIBE gyc_vehicle_info")->fetchAll(PDO::FETCH_ASSOC);
    $hasVehicleUrl = false;
    foreach ($check as $col) {
        if ($col['Field'] === 'vehicle_url') {
            $hasVehicleUrl = true;
            echo "✓ Verified: vehicle_url column exists (" . $col['Type'] . ")\n";
            break;
        }
    }

    if (!$hasVehicleUrl) {
        echo "⚠ WARNING: vehicle_url column not found!\n";
        echo "  This might happen if the table hasn't been created yet.\n";
        echo "  The migration will create it when the scraper first runs.\n";
    }

    echo "\n✅ Database setup complete!\n";

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
