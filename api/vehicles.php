<?php
/**
 * Car Listings API Endpoint
 * 
 * Returns active vehicles as JSON for the frontend.
 * 
 * Usage:
 *   GET /api/vehicles.php              - Get all active vehicles
 *   GET /api/vehicles.php?limit=20     - Limit results
 *   GET /api/vehicles.php?offset=20    - Pagination offset
 *   GET /api/vehicles.php?sort=price   - Sort by field (price, mileage, title)
 *   GET /api/vehicles.php?order=asc    - Sort order (asc, desc)
 *   GET /api/vehicles.php?search=volvo - Search in title
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: max-age=300'); // Cache for 5 minutes

// Error handling
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
    ]);
    error_log("API Error: " . $e->getMessage());
});

// Load configuration
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Database connection
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['database']['host'],
        $config['database']['dbname'],
        $config['database']['charset']
    );
    
    $db = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Parse query parameters
$limit = max(1, min(250, (int)($_GET['limit'] ?? 250)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$sort = $_GET['sort'] ?? 'price';
$order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$search = $_GET['search'] ?? null;

// Validate sort field
$allowedSorts = [
    'price' => 'price_numeric',
    'mileage' => 'mileage_numeric',
    'title' => 'title',
    'created' => 'created_at',
    'updated' => 'updated_at',
];

$sortColumn = $allowedSorts[$sort] ?? 'price_numeric';

// Build query
$source = $config['scraper']['source'];
$params = [$source];
$where = "source = ? AND is_active = 1";

if ($search) {
    $where .= " AND title LIKE ?";
    $params[] = '%' . $search . '%';
}

// Get total count
$countSql = "SELECT COUNT(*) FROM vehicles WHERE $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalCount = (int)$stmt->fetchColumn();

// Get vehicles
$sql = "SELECT 
            id, external_id, title, price, price_numeric, location,
            mileage, colour, transmission, fuel_type, body_style,
            first_reg_date, description_short, description_full,
            image_url, vehicle_url, created_at, updated_at
        FROM vehicles 
        WHERE $where 
        ORDER BY $sortColumn $order 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Output response
echo json_encode([
    'success' => true,
    'meta' => [
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'returned' => count($vehicles),
    ],
    'vehicles' => $vehicles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
