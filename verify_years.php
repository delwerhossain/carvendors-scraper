<?php
$data = json_decode(file_get_contents('data/vehicles.json'), true);

echo "=== YEAR VERIFICATION ===\n";
$years = [];
foreach ($data['vehicles'] as $v) {
    $years[$v['plate_year']] = ($years[$v['plate_year']] ?? 0) + 1;
}
ksort($years);
foreach ($years as $year => $count) {
    echo "$year: $count vehicles\n";
}

echo "\n=== SAMPLE VEHICLES WITH CORRECTED YEARS ===\n";
for ($i = 0; $i < 5; $i++) {
    $v = $data['vehicles'][$i];
    echo "ID: {$v['id']}, Reg: {$v['reg_no']}, Plate: {$v['registration_plate']}, Year: {$v['plate_year']}\n";
    echo "  Title: {$v['title']}\n";
    echo "  Grabber: " . ($v['attention_grabber'] ?: '[NULL]') . "\n\n";
}
?>
