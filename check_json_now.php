<?php
$data = json_decode(file_get_contents('data/vehicles.json'), true);
echo "Total vehicles in JSON: " . count($data['vehicles']) . "\n\n";
echo "First 3 vehicles:\n\n";

for ($i = 0; $i < 3 && $i < count($data['vehicles']); $i++) {
    $v = $data['vehicles'][$i];
    echo "[$i] ID: {$v['id']}\n";
    echo "    Title: " . substr($v['title'], 0, 60) . "\n";
    echo "    Attention Grabber: " . ($v['attention_grabber'] ?? '[NULL]') . "\n";
    echo "    Plate Year: " . ($v['plate_year'] ?? '[NULL]') . "\n\n";
}

// Check how many have attention_grabber
$with_grabber = count(array_filter($data['vehicles'], fn($v) => !empty($v['attention_grabber'])));
echo "Summary:\n";
echo "  With attention_grabber: $with_grabber / " . count($data['vehicles']) . "\n";
?>
