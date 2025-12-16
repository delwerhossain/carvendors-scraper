<?php
/**
 * Fix Vehicle Data Script
 *
 * This script updates missing vehicle data from scraped information
 * It extracts model, year, engine_size, transmission, fuel_type from vehicle titles and saves them to gyc_vehicle_attribute table
 */

require_once 'config.php';
$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset={$config['database']['charset']}",
        $config['database']['username'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "=== Fixing Vehicle Data ===\n\n";

    // Get vehicles with empty attribute data
    $sql = "SELECT v.id, v.reg_no, v.vehicle_url, v.attr_id
            FROM gyc_vehicle_info v
            LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
            WHERE v.vendor_id = 432
            AND (a.model IS NULL OR a.model = '' OR a.engine_size IS NULL OR a.engine_size = '')
            ORDER BY v.created_at DESC
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($vehicles) . " vehicles with missing data\n\n";

    foreach ($vehicles as $vehicle) {
        echo "Processing vehicle ID: {$vehicle['id']}, Reg: {$vehicle['reg_no']}\n";

        // Extract data from vehicle URL (title is encoded in URL)
        $title = basename($vehicle['vehicle_url'], '/');
        $title = str_replace('-', ' ', $title);

        echo "  Title: $title\n";

        // Extract make and model from title
        $makes = ['volvo', 'nissan', 'mercedes', 'vauxhall', 'alfa', 'land', 'rover', 'hyundai', 'audi', 'jeep', 'kia', 'mini', 'bmw', 'citroen', 'peugeot', 'volkswagen', 'renault', 'suzuki', 'ford', 'seat', 'toyota', 'smart', 'ssangyong', 'ds'];

        $make = '';
        $model = '';
        foreach ($makes as $m) {
            if (strpos($title, $m) !== false) {
                $make = ucfirst($m);
                break;
            }
        }

        if ($make) {
            // Extract model by taking the part after make and before engine specs
            $pattern = "/$make\s+(.+?)(?:\s+[0-9]+\.[0-9]+|\s+[0-9]+\s+|\s+euro|\s+$)/i";
            if (preg_match($pattern, $title, $matches)) {
                $model = ucwords($matches[1]);
            }
        }

        // Extract year from title (look for 4-digit numbers starting with 20)
        $year = '';
        if (preg_match('/(20[0-9]{2})/', $title, $matches)) {
            $year = $matches[1];
        }

        // Extract engine size (look for patterns like "2.0", "1.6", etc.)
        $engineSize = '';
        if (preg_match('/([0-9]+\.[0-9]+)/', $title, $matches)) {
            $engineSize = $matches[1];
        }

        // Extract transmission
        $transmission = '';
        if (strpos($title, 'automatic') !== false || strpos($title, 'auto') !== false) {
            $transmission = 'Automatic';
        } elseif (strpos($title, 'manual') !== false) {
            $transmission = 'Manual';
        }

        // Extract fuel type
        $fuelType = '';
        if (strpos($title, 'diesel') !== false || strpos($title, 'tdi') !== false || strpos($title, 'cdti') !== false) {
            $fuelType = 'Diesel';
        } elseif (strpos($title, 'petrol') !== false || strpos($title, 'tgi') !== false) {
            $fuelType = 'Petrol';
        }

        // Extract body style
        $bodyStyle = '';
        if (strpos($title, '5dr') !== false || strpos($title, '5-door') !== false) {
            $bodyStyle = 'Hatchback';
        } elseif (strpos($title, '3dr') !== false || strpos($title, '3-door') !== false) {
            $bodyStyle = 'Hatchback';
        } elseif (strpos($title, 'estate') !== false || strpos($title, 'tourer') !== false) {
            $bodyStyle = 'Estate';
        } elseif (strpos($title, 'suv') !== false) {
            $bodyStyle = 'SUV';
        }

        echo "  Extracted: Make=$make, Model=$model, Year=$year, Engine=$engineSize, Trans=$transmission, Fuel=$fuelType\n";

        // Update or insert into gyc_vehicle_attribute
        if ($vehicle['attr_id'] > 0) {
            // Update existing record
            $updateSql = "UPDATE gyc_vehicle_attribute SET
                          model = ?, year = ?, engine_size = ?,
                          transmission = ?, fuel_type = ?, body_style = ?,
                          updated_at = NOW()
                          WHERE id = ?";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$model, $year, $engineSize, $transmission, $fuelType, $bodyStyle, $vehicle['attr_id']]);

            echo "  ✓ Updated attribute record ID: {$vehicle['attr_id']}\n";
        } else {
            // Insert new record
            $insertSql = "INSERT INTO gyc_vehicle_attribute (
                            category_id, make_id, model, generation, trim,
                            engine_size, fuel_type, transmission, derivative,
                            gearbox, year, body_style, active_status, created_at
                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '1', NOW())";

            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                1,  // category_id
                1,  // make_id (default)
                $model,
                '', // generation
                '', // trim
                $engineSize,
                $fuelType,
                $transmission,
                '', // derivative
                $transmission, // gearbox
                $year,
                $bodyStyle
            ]);

            $newAttrId = $pdo->lastInsertId();

            // Update vehicle info with new attr_id
            $updateVehicleSql = "UPDATE gyc_vehicle_info SET attr_id = ? WHERE id = ?";
            $updateVehicleStmt = $pdo->prepare($updateVehicleSql);
            $updateVehicleStmt->execute([$newAttrId, $vehicle['id']]);

            echo "  ✓ Created new attribute record ID: $newAttrId and linked to vehicle\n";
        }

        echo "\n";
    }

    echo "=== Verification ===\n\n";

    // Check updated records
    $verifySql = "SELECT v.id, v.reg_no, a.model, a.year, a.engine_size, a.transmission, a.fuel_type
                  FROM gyc_vehicle_info v
                  LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
                  WHERE v.vendor_id = 432
                  ORDER BY v.created_at DESC
                  LIMIT 5";

    $verifyStmt = $pdo->prepare($verifySql);
    $verifyStmt->execute();
    $results = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $result) {
        echo "ID: {$result['id']}, Reg: {$result['reg_no']}\n";
        echo "  Model: {$result['model']}, Year: {$result['year']}\n";
        echo "  Engine: {$result['engine_size']}, Transmission: {$result['transmission']}, Fuel: {$result['fuel_type']}\n";
        echo "  ---\n";
    }

    echo "\n✅ Vehicle data fix completed!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}