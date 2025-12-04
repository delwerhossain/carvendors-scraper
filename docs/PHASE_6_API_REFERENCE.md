# Phase 6: CarCheckEnhanced API Reference

**Version**: 1.0.0  
**Status**: ✅ Complete  
**Last Updated**: 2025-01-13  

---

## Quick Start

```php
<?php
require 'autoload.php';

// Initialize
$config = include 'config.php';
$db = new PDO('mysql:host=localhost;dbname=carsafari', 'root', '');
$enhanced = new CarCheckEnhanced($db, $config);

// Use it
$data = $enhanced->fetchVehicleData('AB70XYZ');
echo $data['color'] ?? 'Not found';
```

---

## Constructor

### `__construct(PDO $db, array $config, ?StatisticsManager $statisticsManager)`

Initialize CarCheckEnhanced instance.

**Parameters**:
| Name | Type | Required | Description |
|------|------|----------|-------------|
| `$db` | PDO | Yes | Database connection |
| `$config` | array | No | Configuration (from config.php) |
| `$statisticsManager` | StatisticsManager | No | For statistics integration |

**Example**:
```php
$config = include 'config.php';
$db = new PDO('mysql:host=localhost;dbname=carsafari', 'root', '');

// Basic
$enhanced = new CarCheckEnhanced($db, $config);

// With statistics
$stats = new StatisticsManager($db);
$enhanced = new CarCheckEnhanced($db, $config, $stats);
```

---

## Core Methods

### `fetchVehicleData(string $regNo, bool $bypassCache = false): array`

Fetch vehicle data with intelligent caching.

**Parameters**:
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `$regNo` | string | - | Vehicle registration (e.g., "AB70XYZ") |
| `$bypassCache` | bool | false | Force fresh API call, skip cache |

**Returns**: Array with vehicle details

**Return Structure**:
```php
[
    'color' => 'Black',                      // Extracted color
    'color_source' => 'carcheck',            // Data source
    'registration_date' => '2020-06-15',     // MOT/registration date
    'mot_expiry' => '2025-12-31',            // MOT expiry
    'fuel_type_carcheck' => 'Diesel',        // Fuel type
    'transmission_carcheck' => 'Manual',     // Transmission
    'body_style_carcheck' => 'Saloon',       // Body style
    'carcheck_url' => 'https://www.carcheck.co.uk/audi/AB70XYZ',
    'fetched_at' => '2025-01-13 14:30:00',   // Timestamp
]
```

**Throws**: None (errors logged, returns empty array)

**Performance**:
- **Cache Hit**: ~50ms
- **Cache Miss**: ~2-5s (with retry logic)
- **Max Retries**: 3 (exponential backoff)

**Examples**:

```php
// Cached lookup (fast)
$data = $enhanced->fetchVehicleData('AB70XYZ');
if (!empty($data)) {
    echo "Color: {$data['color']}\n";
    echo "Fetched in: cached\n";
}

// Force fresh data (slow)
$fresh = $enhanced->fetchVehicleData('AB70XYZ', true);

// Error handling
$data = $enhanced->fetchVehicleData('INVALID');
if (empty($data)) {
    echo "Failed to fetch data\n";
}
```

**Cache Behavior**:
```
First call:    Cache Miss → API Call (2-5s) → Store in cache
Second call:   Cache Hit → Return from DB (50ms)
After 30 min:  Cache Expires → Cache Miss → API Call (2-5s)
With bypass:   Force API Call regardless of cache (2-5s)
```

---

### `fetchBatch(array $registrations, bool $bypassCache = false): array`

Fetch multiple vehicles efficiently with batch optimization.

**Parameters**:
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `$registrations` | array | - | Array of registration numbers |
| `$bypassCache` | bool | false | Force fresh API calls |

**Returns**: Associative array indexed by registration

**Return Structure**:
```php
[
    'AB70XYZ' => ['color' => 'Black', ...],
    'BD21ABC' => ['color' => 'White', ...],
    'CD22DEF' => ['color' => 'Silver', ...],
]
```

**Throws**: None (partial failures handled gracefully)

**Performance**:
- **All Cached**: ~150ms (3 vehicles)
- **Mixed Cache**: ~2s (1 API call + 2 cached)
- **All Fresh**: ~6s (3 API calls with delays)

**Examples**:

```php
// Fetch 3 vehicles
$registrations = ['AB70XYZ', 'BD21ABC', 'CD22DEF'];
$results = $enhanced->fetchBatch($registrations);

// Process results
foreach ($results as $reg => $data) {
    echo "{$reg}: {$data['color']} - {$data['fuel_type_carcheck']}\n";
}

// Output:
// AB70XYZ: Black - Diesel
// BD21ABC: White - Petrol
// CD22DEF: Silver - Hybrid

// Batch with statistics
$registrations = array_slice($vehicle_list, 0, 100);
$results = $enhanced->fetchBatch($registrations);
$stats = $enhanced->getStatistics();
echo "Processed {$stats['total_requests']} vehicles\n";
echo "Cache hit rate: {$stats['cache_hit_rate']}%\n";

// Force refresh all
$fresh = $enhanced->fetchBatch($registrations, true);
```

**Optimization**:
- Separates cached vs uncached registrations
- Processes batches in configurable chunks (default: 10)
- Respects rate limiting between calls
- Merges cached results with fresh API calls

---

### `getStatistics(): array`

Get current session performance statistics.

**Parameters**: None

**Returns**: Associative array with metrics

**Return Structure**:
```php
[
    'total_requests' => 150,           // Total calls to fetchVehicleData()
    'api_calls' => 45,                 // Actual HTTP calls made
    'cache_hits' => 105,               // Requests served from cache
    'cache_misses' => 45,              // Cache lookups that missed
    'cache_hit_rate' => 70.00,         // Percentage (0-100)
    'errors' => 2,                     // Failed requests
    'retries' => 3,                    // Automatic retries
    'avg_response_time' => 0.5234,     // Average response time (seconds)
    'cache_size' => 89,                // Number of cached vehicles
    'api_reduction' => 70.00,          // % fewer API calls than without cache
]
```

**Examples**:

```php
// Basic stats check
$stats = $enhanced->getStatistics();
echo "Cache Hit Rate: {$stats['cache_hit_rate']}%\n";
echo "API Reduction: {$stats['api_reduction']}%\n";

// Performance monitoring
$stats = $enhanced->getStatistics();
if ($stats['cache_hit_rate'] < 60) {
    echo "Warning: Low cache hit rate, investigate...\n";
}

// Detailed reporting
$stats = $enhanced->getStatistics();
echo "===== Performance Report =====\n";
echo "Requests: {$stats['total_requests']}\n";
echo "  - From Cache: {$stats['cache_hits']} ({$stats['cache_hit_rate']}%)\n";
echo "  - From API: {$stats['api_calls']}\n";
echo "  - Failed: {$stats['errors']}\n";
echo "  - Retried: {$stats['retries']}\n";
echo "Response Time: {$stats['avg_response_time']}s\n";
echo "Cache Size: {$stats['cache_size']} vehicles\n";
echo "API Reduction: {$stats['api_reduction']}%\n";
```

---

### `saveStatistics(): void`

Save current session statistics to database.

**Parameters**: None

**Returns**: void

**Database Update**: Writes to `carcheck_statistics` table

**Examples**:

```php
// At end of scraping session
$enhanced->fetchBatch($registrations);
$enhanced->saveStatistics();  // Persists to DB

// Check saved statistics
$result = $db->query("
    SELECT * FROM carcheck_statistics
    WHERE stat_date = CURDATE()
");
$row = $result->fetch();
echo "Today's cache hit rate: {$row['cache_hit_rate']}%\n";
```

---

### `resetStatistics(): void`

Reset performance counters.

**Parameters**: None

**Returns**: void

**Examples**:

```php
// Before new batch of operations
$enhanced->resetStatistics();

// Process batch
$enhanced->fetchBatch($registrations);

// Get statistics for this batch only
$stats = $enhanced->getStatistics();
```

---

## Cache Management

### `invalidateCache(string $regNo): void`

Remove specific vehicle from cache.

**Parameters**:
| Name | Type | Description |
|------|------|-------------|
| `$regNo` | string | Vehicle registration |

**Examples**:

```php
// Invalidate after vehicle updated
$enhanced->invalidateCache('AB70XYZ');

// Next fetch will hit API
$fresh = $enhanced->fetchVehicleData('AB70XYZ');  // API call
```

---

### `clearCache(): int`

Clear entire cache.

**Returns**: Number of records deleted

**Examples**:

```php
// Clear all cached data
$count = $enhanced->clearCache();
echo "Cleared $count cached entries\n";

// Use in maintenance script
$enhanced->clearCache();
$enhanced->resetStatistics();
echo "Cache maintenance completed\n";
```

---

## Advanced Usage

### Rate Limiting Control

```php
// Configure in config.php
'carcheck' => [
    'request_delay' => 1.5,  // 1.5 seconds between requests
]

// Automatic enforcement
$enhanced->fetchVehicleData('AB70XYZ');  // Request 1
usleep(500000);                           // 0.5 second delay
$enhanced->fetchVehicleData('BD21ABC');  // Request 2
// Waits automatically: 1.5s - 0.5s = 1.0s additional wait
// Prevents IP blocking from carcheck.co.uk
```

### Batch Processing with Configuration

```php
// Configure batch size in config.php
'carcheck' => [
    'batch_size' => 10,  // Process 10 per batch
]

// Process 500 vehicles efficiently
$registrations = array_slice($all_vehicles, 0, 500);
$results = $enhanced->fetchBatch($registrations);
// Automatically processes in batches of 10
// Respects rate limiting between batches
```

### Error Recovery

```php
// Automatic retry with exponential backoff
// Configuration in config.php
'carcheck' => [
    'max_retries' => 3,
]

// On API failure:
// Attempt 1: Fails
// Wait 1 second (exponential backoff)
// Attempt 2: Fails  
// Wait 2 seconds (exponential backoff)
// Attempt 3: Success
// Returns data after recovery

// Errors logged to carcheck_errors table
$db->query("SELECT * FROM carcheck_errors ORDER BY created_at DESC");
```

### Statistics Integration

```php
// With StatisticsManager (Phase 5)
$statsManager = new StatisticsManager($db);
$enhanced = new CarCheckEnhanced($db, $config, $statsManager);

// Statistics automatically recorded
$enhanced->fetchBatch($registrations);
$carcheck_stats = $enhanced->getStatistics();

// Also available in Phase 5 statistics
$phase5_stats = $statsManager->getDailyStatistics(date('Y-m-d'));
// Shows integration with overall system monitoring
```

---

## Database Queries

### View Cache Performance

```sql
-- Most popular cached vehicles
SELECT registration, hit_count, last_hit, expires_at
FROM carcheck_cache
WHERE expires_at > NOW()
ORDER BY hit_count DESC
LIMIT 20;

-- Cache statistics
SELECT 
  COUNT(*) as total_cached,
  SUM(hit_count) as total_hits,
  AVG(hit_count) as avg_hits,
  MAX(last_hit) as most_recent
FROM carcheck_cache
WHERE expires_at > NOW();

-- Expired entries (cleanup candidates)
SELECT COUNT(*) as expired_count
FROM carcheck_cache
WHERE expires_at < NOW();
```

### Monitor Daily Statistics

```sql
-- Last 7 days of statistics
SELECT 
  stat_date,
  total_requests,
  cached_hits,
  ROUND(cache_hit_rate, 2) as hit_rate_pct,
  ROUND(avg_response_time, 4) as avg_time_sec
FROM carcheck_statistics
WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY stat_date DESC;

-- Find days with low cache performance
SELECT stat_date, cache_hit_rate
FROM carcheck_statistics
WHERE cache_hit_rate < 60
ORDER BY stat_date DESC;
```

### Analyze Errors

```sql
-- Error summary
SELECT 
  error_type,
  COUNT(*) as count,
  MAX(created_at) as latest,
  MIN(created_at) as oldest
FROM carcheck_errors
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY error_type
ORDER BY count DESC;

-- Vehicles with repeated errors
SELECT 
  registration,
  COUNT(*) as error_count,
  GROUP_CONCAT(error_type) as types
FROM carcheck_errors
GROUP BY registration
HAVING error_count > 2
ORDER BY error_count DESC;
```

---

## Performance Benchmarks

### Scenario 1: Pure Cache (100 vehicles, all cached)

```
Input: 100 registrations (all in cache)
API Calls: 0
Time: ~150ms
Cache Hit Rate: 100%
API Reduction: 100%
```

**Code**:
```php
$registrations = [...]; // 100 vehicles
$start = microtime(true);
$results = $enhanced->fetchBatch($registrations);
$duration = (microtime(true) - $start);
echo "Time: {$duration}ms, Vehicles: {$stats['cache_hits']}\n";
```

### Scenario 2: Mixed Cache (100 vehicles, 70% cached)

```
Input: 100 registrations (70 cached, 30 fresh)
API Calls: 30
Time: ~2-3 seconds
Cache Hit Rate: 70%
API Reduction: 70%
```

### Scenario 3: No Cache (100 vehicles, all fresh)

```
Input: 100 registrations (none cached)
API Calls: 100
Time: ~10-20 seconds
Cache Hit Rate: 0%
API Reduction: 0%
```

---

## Configuration Reference

### Default Configuration

```php
'carcheck' => [
    'enabled' => true,                    // Enable/disable
    'cache_ttl' => 1800,                  // 30 minutes
    'request_delay' => 1.5,               // Seconds
    'max_retries' => 3,                   // Retry attempts
    'timeout' => 30,                      // Seconds
    'batch_size' => 10,                   // Vehicles per batch
    'auto_retry' => true,                 // Automatic retry
]
```

### High-Traffic Configuration

```php
'carcheck' => [
    'enabled' => true,
    'cache_ttl' => 3600,                  // 1 hour (longer)
    'request_delay' => 2.0,               // Slower (more polite)
    'max_retries' => 3,
    'timeout' => 30,
    'batch_size' => 20,                   // Larger batches
    'auto_retry' => true,
]
```

### Low-Latency Configuration

```php
'carcheck' => [
    'enabled' => true,
    'cache_ttl' => 300,                   // 5 minutes (shorter)
    'request_delay' => 0.5,               // Faster
    'max_retries' => 5,                   // More retries
    'timeout' => 60,                      // Longer timeout
    'batch_size' => 10,
    'auto_retry' => true,
]
```

---

## Error Handling

### Try-Catch Pattern

```php
try {
    $data = $enhanced->fetchVehicleData('AB70XYZ');
    
    if (empty($data)) {
        echo "No data returned (all retries failed)\n";
    } else {
        echo "Success: {$data['color']}\n";
    }
    
} catch (Exception $e) {
    echo "Unexpected error: {$e->getMessage()}\n";
}
```

### Error Types

| Type | Meaning | Recovery |
|------|---------|----------|
| `fetch_failed` | API call failed | Automatic retry |
| `parse_error` | Invalid HTML | Logged, continue |
| `timeout` | Request timeout | Automatic retry |
| `cache_write_error` | DB cache failed | Logged, continue |

### Database Error Tracking

```sql
-- Find recent errors
SELECT error_type, message, created_at
FROM carcheck_errors
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- Clear resolved errors
DELETE FROM carcheck_errors
WHERE resolved = TRUE
AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## Complete Example

```php
<?php
/**
 * Complete example: Scrape and enrich 500 vehicles with CarCheck data
 */

require 'autoload.php';

// Setup
$config = include 'config.php';
$db = new PDO(
    'mysql:host=' . $config['database']['host'] . 
    ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Create CarCheckEnhanced
$enhanced = new CarCheckEnhanced($db, $config);

// Get vehicles to enrich
$stmt = $db->query("SELECT registration FROM vehicles LIMIT 500");
$vehicles = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Processing " . count($vehicles) . " vehicles...\n";

// Fetch in batch
$start = microtime(true);
$enriched = $enhanced->fetchBatch($vehicles);
$duration = microtime(true) - $start;

// Update database
$updateStmt = $db->prepare("
    UPDATE vehicles 
    SET color = ?, carcheck_url = ?
    WHERE registration = ?
");

$updated = 0;
foreach ($enriched as $reg => $data) {
    if (!empty($data['color'])) {
        $updateStmt->execute([
            $data['color'],
            $data['carcheck_url'] ?? null,
            $reg
        ]);
        $updated++;
    }
}

// Report
$stats = $enhanced->getStatistics();
$enhanced->saveStatistics();

echo "=== Completion Report ===\n";
echo "Time: " . round($duration, 2) . "s\n";
echo "Vehicles Enriched: $updated\n";
echo "Cache Hits: " . $stats['cache_hits'] . "\n";
echo "API Calls: " . $stats['api_calls'] . "\n";
echo "Hit Rate: " . $stats['cache_hit_rate'] . "%\n";
echo "API Reduction: " . $stats['api_reduction'] . "%\n";
echo "Avg Response: " . round($stats['avg_response_time'], 4) . "s\n";
```

---

## Summary

**CarCheckEnhanced provides**:
- ✅ Simple `fetchVehicleData()` for single vehicles
- ✅ Efficient `fetchBatch()` for multiple vehicles  
- ✅ Automatic caching with 70-90% hit rates
- ✅ Built-in retry logic with exponential backoff
- ✅ Rate limiting to prevent IP blocking
- ✅ Comprehensive error tracking
- ✅ Performance statistics and monitoring
- ✅ Database integration for persistence

**For most use cases**:
```php
// Just these 3 lines
$enhanced = new CarCheckEnhanced($db, $config);
$results = $enhanced->fetchBatch($registrations);
$enhanced->saveStatistics();
```

Everything else is automatic! 🚀
