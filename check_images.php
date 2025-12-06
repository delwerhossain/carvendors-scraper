<?php
$config = include 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    ['charset' => 'utf8mb4']
);

echo "=== IMAGE URL QUALITY CHECK ===\n\n";

// Check for medium images
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images WHERE file_name LIKE '%/medium/%'");
$medium = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "[1] Medium images: $medium\n";

// Check for incomplete URLs
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images WHERE file_name LIKE 'https://%' AND (file_name NOT LIKE '%.jpg' AND file_name NOT LIKE '%.png' AND file_name NOT LIKE '%.webp')");
$incomplete = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "[2] Incomplete URLs (no extension): $incomplete\n";

if ($incomplete > 0) {
    echo "\n    Sample incomplete URLs:\n";
    $stmt = $pdo->query("SELECT file_name FROM gyc_product_images WHERE file_name LIKE 'https://%' AND (file_name NOT LIKE '%.jpg' AND file_name NOT LIKE '%.png' AND file_name NOT LIKE '%.webp') LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r) {
        echo "    - " . substr($r['file_name'], 0, 80) . "\n";
    }
}

// Check large images
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images WHERE file_name LIKE '%/large/%'");
$large = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "\n[3] Large images: $large\n";

// Check total
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM gyc_product_images");
$total = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "\n[4] Total images: $total\n";
echo "    Medium: $medium\n";
echo "    Large: $large\n";
echo "    Other: " . ($total - $medium - $large) . "\n";
?>
