<?php
$config = require 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
        $config['database']['username'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Read and execute the migration
    $sql = file_get_contents('ALTER_DB_ADD_URL.sql');

    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            echo "Executing: " . substr($statement, 0, 60) . "...\n";
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Column might already exist, that's OK
                if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                    echo "  (Column already exists, skipping)\n";
                } else if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "  (Index already exists, skipping)\n";
                } else {
                    throw $e;
                }
            }
        }
    }

    echo "✓ Migration applied successfully!\n\n";

    // Verify the column exists
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
        echo "✗ ERROR: vehicle_url column not found!\n";
    }

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
