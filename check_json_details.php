<?php
$data = json_decode(file_get_contents('data/vehicles.json'), true);

echo "=== VEHICLES WITH attention_grabber ===\n";
foreach ($data['vehicles'] as $i => $v) {
    if (!empty($v['attention_grabber'])) {
        echo "\n[$i] ID: {$v['id']}\n";
        echo "    Title: " . substr($v['title'], 0, 70) . "\n";
        echo "    Attention Grabber: {$v['attention_grabber']}\n";
        echo "    Plate Year: {$v['plate_year']}\n";
    }
}

echo "\n\n=== SAMPLE VEHICLES (checking title field) ===\n";
for ($i = 0; $i < 5; $i++) {
    $v = $data['vehicles'][$i];
    echo "\n[$i] Title: " . $v['title'] . "\n";
    echo "    Attention Grabber: " . ($v['attention_grabber'] ?: '[NULL]') . "\n";
}

// Check year distribution
$years = array_map(fn($v) => (int)($v['plate_year'] ?? 0), $data['vehicles']);
sort($years);
echo "\n\n=== YEAR STATS ===\n";
echo "Min year: " . min($years) . "\n";
echo "Max year: " . max($years) . "\n";
echo "Vehicles with invalid years (1900-1999): " . count(array_filter($years, fn($y) => $y < 2000)) . "\n";
?>
