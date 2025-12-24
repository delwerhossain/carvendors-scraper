<?php
/**
 * Fetch raw description from a vehicle listing to analyze formatting
 */

require 'config.php';

// Use the same HTTP client setup as the scraper
$options = [
    'http' => [
        'timeout' => 30,
        'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
];

if (!$config['scraper']['verify_ssl']) {
    $options['ssl'] = [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ];
}

$context = stream_context_create($options);
$url = 'https://systonautosltd.co.uk/vehicle/name/citroen-c1-1-2-puretech-flair-euro-6-5dr/';

echo "Fetching: $url\n";
echo str_repeat('=', 80) . "\n";

$html = @file_get_contents($url, false, $context);
if (!$html) {
    die("ERROR: Could not fetch URL\n");
}

// Extract description - look for the specs/description section
// Usually in a specific div or section
if (preg_match('/class="description"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
    $raw_desc = $matches[1];
} elseif (preg_match('/class="vehicle-description"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
    $raw_desc = $matches[1];
} else {
    // Try to find any large text block
    preg_match('/<div[^>]*>(.*###THIS VEHICLE.*)<\/div>/is', $html, $matches);
    $raw_desc = $matches[1] ?? '';
}

// Clean HTML tags but preserve structure
$text = strip_tags($raw_desc);
$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

echo "RAW DESCRIPTION (first 2000 chars):\n";
echo str_repeat('-', 80) . "\n";
echo substr($text, 0, 2000) . "\n";
echo str_repeat('-', 80) . "\n\n";

// Show with visible newlines and pipes
$visible = str_replace("\n", "\\n", substr($text, 0, 1000));
$visible = str_replace("|", " | ", $visible);
echo "VISIBLE FORMAT (with \\n and | markers):\n";
echo $visible . "\n";
echo str_repeat('-', 80) . "\n\n";

// Analyze structure
echo "ANALYSIS:\n";
$lines = explode("\n", $text);
echo "Total lines: " . count($lines) . "\n";
echo "First 20 lines:\n";
foreach (array_slice($lines, 0, 20) as $i => $line) {
    $line_clean = trim($line);
    $pipe_count = substr_count($line_clean, '|');
    echo "  Line " . ($i+1) . " (pipes: $pipe_count): " . substr($line_clean, 0, 80) . (strlen($line_clean) > 80 ? '...' : '') . "\n";
}
