<?php
$config = include 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    ['charset' => 'utf8mb4']
);

echo "=== ATTENTION_GRABBER CHECK ===\n\n";

// Check if column exists
$stmt = $pdo->query("DESCRIBE gyc_vehicle_info");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
$has_col = false;
foreach($cols as $c) {
    if ($c['Field'] === 'attention_grabber') {
        $has_col = true;
        echo "[✓] Column 'attention_grabber' EXISTS\n";
        echo "    Type: {$c['Type']}\n";
        echo "    Null: {$c['Null']}\n\n";
        break;
    }
}

if (!$has_col) {
    echo "[✗] Column 'attention_grabber' NOT FOUND\n";
    echo "    Need to add this field to gyc_vehicle_info\n\n";
}

// Check sample data
echo "[Sample Data]\n";
$stmt = $pdo->query("SELECT id, reg_no, attention_grabber FROM gyc_vehicle_info LIMIT 3");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) {
    $val = empty($r['attention_grabber']) ? '[NULL]' : substr($r['attention_grabber'], 0, 50);
    echo "ID {$r['id']}: {$val}\n";
}

// Count NULL values
echo "\n[Statistics]\n";
if ($has_col) {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN attention_grabber IS NULL THEN 1 ELSE 0 END) as null_count,
        SUM(CASE WHEN attention_grabber = '' THEN 1 ELSE 0 END) as empty_count
    FROM gyc_vehicle_info");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total records: {$stats['total']}\n";
    echo "NULL values: {$stats['null_count']}\n";
    echo "Empty values: {$stats['empty_count']}\n";
    echo "With data: " . ($stats['total'] - $stats['null_count'] - $stats['empty_count']) . "\n";
}
?>
