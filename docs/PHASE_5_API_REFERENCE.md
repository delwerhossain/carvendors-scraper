# Phase 5: Statistics Manager API Reference

## Complete Method Reference

### Table of Contents
1. [Initialization & Configuration](#initialization--configuration)
2. [Statistics Recording](#statistics-recording)
3. [Finalization & Storage](#finalization--storage)
4. [Historical Queries](#historical-queries)
5. [Reporting](#reporting)
6. [Integration with CarSafariScraper](#integration-with-carsafariscraper)

---

## Initialization & Configuration

### `initializeStatistics()`

```php
public function initializeStatistics(int $vendorId = 432, string $sourceName = null): void
```

**Purpose**: Initialize statistics tracking for a new scrape run.

**Parameters**:
- `$vendorId` (int): Vendor ID for the scrape (default: 432)
- `$sourceName` (string, optional): Human-readable source name (default: "Systonautos Ltd")

**Usage**:
```php
$stats = new StatisticsManager($db, $config);
$stats->initializeStatistics(432, 'Systonautos Ltd');
// Statistics tracking now active
```

**Notes**:
- Must be called before recording any statistics
- Initializes internal counters and start time
- Called automatically by CarSafariScraper::runWithCarSafari()

---

### `constructor()`

```php
public function __construct(PDO $db, array $config = [])
```

**Purpose**: Create a new StatisticsManager instance.

**Parameters**:
- `$db` (PDO): Database connection
- `$config` (array): Configuration array

**Usage**:
```php
$db = new PDO('mysql:host=localhost;dbname=carsafari', 'user', 'pass');
$statsManager = new StatisticsManager($db, $config);
```

**Notes**:
- Database must be initialized and accessible
- Configuration array passed for future use

---

## Statistics Recording

### `recordVehicleAction()`

```php
public function recordVehicleAction(string $action, int $count = 1): void
```

**Purpose**: Record vehicle processing action counts.

**Parameters**:
- `$action` (string): Action type: 'inserted', 'updated', 'skipped', 'deactivated'
- `$count` (int): Number of vehicles (default: 1)

**Valid Actions**:
- `inserted` - New vehicles added
- `updated` - Existing vehicles updated
- `skipped` - Vehicles unchanged (no action)
- `deactivated` - Vehicles set inactive

**Usage**:
```php
$stats->recordVehicleAction('inserted', 5);
$stats->recordVehicleAction('updated', 2);
$stats->recordVehicleAction('skipped', 74);
```

**Called By**:
- CarSafariScraper during vehicle processing loop

**Notes**:
- 'found' count automatically incremented for non-skipped actions
- Will throw Exception for invalid actions

---

### `recordImageStatistics()`

```php
public function recordImageStatistics(int $totalImages, int $imagesStored): void
```

**Purpose**: Record image processing statistics.

**Parameters**:
- `$totalImages` (int): Total images processed
- `$imagesStored` (int): Number of images successfully stored

**Usage**:
```php
$stats->recordImageStatistics(150, 145); // 150 total, 145 stored
```

**Called By**:
- CarSafariScraper after image download loop

**Notes**:
- Called once per scrape run (not per image)
- Stores totals only, not individual image details

---

### `recordError()`

```php
public function recordError(
    string $errorType,
    string $message,
    string $errorCode = null,
    int $vehicleId = null
): void
```

**Purpose**: Record an error occurrence.

**Parameters**:
- `$errorType` (string): Category of error
- `$message` (string): Error message/description
- `$errorCode` (string, optional): Error code (e.g., 'E_PARSE')
- `$vehicleId` (int, optional): ID of affected vehicle

**Error Types**:
- `network` - Network/HTTP errors
- `parse` - HTML parsing errors
- `database` - Database errors
- `timeout` - Request timeout
- `validation` - Data validation errors
- `runtime` - General runtime errors

**Usage**:
```php
try {
    // Some operation
} catch (Exception $e) {
    $stats->recordError(
        'parse',
        'Failed to parse vehicle description',
        'E_PARSE_DESC',
        $vehicleId
    );
}
```

**Stored In**: Internal errors array, persisted in scraper_error_log table

**Notes**:
- Increments error_count counter
- Stores full error details for later retrieval
- Used for anomaly detection and trend analysis

---

### `recordWarning()`

```php
public function recordWarning(string $message): void
```

**Purpose**: Record a non-fatal warning.

**Parameters**:
- `$message` (string): Warning message

**Usage**:
```php
$stats->recordWarning('Vehicle color not in whitelist: ' . $color);
$stats->recordWarning('Processing slower than normal');
```

**Stored In**: Internal warnings array (not persisted separately)

**Notes**:
- Increments warning_count counter
- Warnings are informational, not critical
- Included in run statistics summary

---

## Finalization & Storage

### `finalizeStatistics()`

```php
public function finalizeStatistics(
    string $status = 'completed',
    string $errorMessage = null
): array
```

**Purpose**: Calculate derived metrics and finalize run statistics.

**Parameters**:
- `$status` (string): Run status ('completed', 'partial', 'failed', 'timeout')
- `$errorMessage` (string, optional): Error message if status is not 'completed'

**Return**:
- Array of completed statistics with calculated metrics

**Status Values**:
- `completed` - Scrape completed successfully
- `partial` - Scrape completed but with some failures
- `failed` - Scrape failed entirely
- `timeout` - Scrape timed out

**Calculated Metrics**:
```php
[
    'processing_time_seconds' => 150,
    'processing_time_formatted' => '2m 30s',
    'skip_percentage' => 96.3,
    'error_percentage' => 1.2,
    'avg_processing_per_vehicle' => 1.85, // seconds
    'avg_processing_rate' => 0.54 // vehicles/sec
]
```

**Usage**:
```php
try {
    // Scrape operations...
    $stats->finalizeStatistics('completed');
} catch (Exception $e) {
    $stats->finalizeStatistics('failed', $e->getMessage());
}
```

**Must Be Called**:
- Before saveStatistics()
- After all vehicle/image recording is complete

**Notes**:
- Calculates processing duration
- Computes percentage metrics
- Determines anomalies

---

### `saveStatistics()`

```php
public function saveStatistics(): int
```

**Purpose**: Persist all statistics to database.

**Return**:
- (int) ID of saved statistics record

**Database Writes**:
1. Insert into scraper_statistics
2. Insert error details into scraper_error_log
3. Detect anomalies and update flags
4. Update scraper_statistics_daily

**Usage**:
```php
$stats->finalizeStatistics('completed');
$statsId = $stats->saveStatistics();
echo "Statistics saved with ID: $statsId";
```

**Exceptions**:
- Throws Exception if finalizeStatistics() not called first
- Throws Exception if database write fails

**Must Precede**:
- Any queries expecting the statistics to be in database

**Notes**:
- Automatically detects anomalies
- Automatically updates daily summary
- Creates relationships with errors

---

## Historical Queries

### `getStatisticsForDateRange()`

```php
public function getStatisticsForDateRange(
    string $startDate,
    string $endDate,
    int $vendorId = 432
): array
```

**Purpose**: Retrieve statistics for a date range.

**Parameters**:
- `$startDate` (string): Start date in YYYY-MM-DD format
- `$endDate` (string): End date in YYYY-MM-DD format
- `$vendorId` (int): Vendor ID to filter (default: 432)

**Return**:
- Array of statistics records ordered by scrape_datetime DESC

**Record Fields**:
- All fields from scraper_statistics table
- Includes calculated metrics and anomaly flags

**Usage**:
```php
$stats = $statsManager->getStatisticsForDateRange(
    '2024-12-01',
    '2024-12-31'
);

foreach ($stats as $run) {
    echo $run['scrape_date'] . ": " . $run['vehicles_found'] . " vehicles\n";
}
```

**Indexed For Performance**: idx_vendor_date

**Notes**:
- Returns all runs within date range
- Useful for trend analysis
- Handles multiple runs per day

---

### `getDailyStatistics()`

```php
public function getDailyStatistics(int $days = 30, int $vendorId = 432): array
```

**Purpose**: Get daily summary statistics for recent days.

**Parameters**:
- `$days` (int): Number of days to retrieve (default: 30)
- `$vendorId` (int): Vendor ID filter (default: 432)

**Return**:
- Array of daily summary records, most recent first

**Daily Summary Fields**:
- `scrape_date` (DATE)
- `total_runs` (INT) - Number of runs that day
- `successful_runs` (INT) - Runs that completed
- `failed_runs` (INT) - Runs that failed
- `total_vehicles_found` (INT)
- `total_vehicles_inserted` (INT)
- `total_vehicles_updated` (INT)
- `total_vehicles_skipped` (INT)
- `avg_skip_percentage` (DECIMAL)
- `total_images_stored` (INT)

**Usage**:
```php
$daily = $statsManager->getDailyStatistics(7);

foreach ($daily as $day) {
    echo $day['scrape_date'] . ": ";
    echo $day['total_runs'] . " runs, ";
    echo $day['total_vehicles_found'] . " vehicles\n";
}
```

**Performance**: Indexed for fast retrieval

**Notes**:
- Aggregates multiple runs per day
- Useful for dashboards and monitoring
- More efficient than querying individual runs

---

### `generateWeeklyReport()`

```php
public function generateWeeklyReport(
    string $weekStartDate,
    int $vendorId = 432
): array
```

**Purpose**: Generate weekly summary statistics.

**Parameters**:
- `$weekStartDate` (string): Week start date (Monday) in YYYY-MM-DD format
- `$vendorId` (int): Vendor ID (default: 432)

**Return**:
```php
[
    'week_start' => '2024-12-09',
    'week_end' => '2024-12-15',
    'total_runs' => 14,
    'successful_runs' => 13,
    'total_vehicles' => 567,
    'total_inserted' => 23,
    'total_updated' => 12,
    'total_skipped' => 532,
    'total_images' => 1200,
    'total_images_stored' => 1180,
    'avg_skip_rate' => 93.5,
    'avg_duration' => 145,
    'total_errors' => 3,
    'has_anomalies' => false
]
```

**Usage**:
```php
$report = $statsManager->generateWeeklyReport('2024-12-09');

echo "Week {$report['week_start']} to {$report['week_end']}\n";
echo "Runs: {$report['successful_runs']}/{$report['total_runs']}\n";
echo "Vehicles: {$report['total_vehicles']}\n";
echo "Skip Rate: {$report['avg_skip_rate']}%\n";
```

**Notes**:
- Automatically calculates 7-day period
- Returns aggregated metrics across all runs that week
- Useful for weekly reporting

---

### `generateMonthlyReport()`

```php
public function generateMonthlyReport(int $year, int $month, int $vendorId = 432): array
```

**Purpose**: Generate monthly summary statistics.

**Parameters**:
- `$year` (int): Year (e.g., 2024)
- `$month` (int): Month 1-12
- `$vendorId` (int): Vendor ID (default: 432)

**Return**:
```php
[
    'month' => 12,
    'year' => 2024,
    'total_runs' => 60,
    'successful_runs' => 58,
    'total_vehicles' => 2350,
    'total_inserted' => 87,
    'total_updated' => 45,
    'total_skipped' => 2218,
    'total_images' => 5000,
    'total_images_stored' => 4890,
    'avg_skip_rate' => 94.3,
    'avg_duration' => 140,
    'total_errors' => 12,
    'days_with_runs' => 30
]
```

**Usage**:
```php
$report = $statsManager->generateMonthlyReport(2024, 12);

echo "December 2024 Report\n";
echo "Total Runs: {$report['total_runs']}\n";
echo "Success Rate: " . round(($report['successful_runs']/$report['total_runs'])*100, 1) . "%\n";
echo "Total Vehicles: {$report['total_vehicles']}\n";
```

**Notes**:
- Aggregates entire month (1-30/31)
- Useful for executive reporting
- Good for trend analysis

---

### `getErrorTrends()`

```php
public function getErrorTrends(int $days = 7, int $vendorId = 432): array
```

**Purpose**: Get error type trends for analysis.

**Parameters**:
- `$days` (int): Number of days to analyze (default: 7)
- `$vendorId` (int): Vendor ID (default: 432)

**Return**:
```php
[
    [
        'error_type' => 'parse',
        'occurrence_count' => 5,
        'error_codes' => 'E_PARSE_001,E_PARSE_002',
        'max_severity' => 'medium',
        'days_affected' => 3,
        'most_recent' => '2024-12-10 14:32:15'
    ],
    [
        'error_type' => 'network',
        'occurrence_count' => 2,
        'error_codes' => 'E_TIMEOUT',
        'max_severity' => 'high',
        'days_affected' => 1,
        'most_recent' => '2024-12-10 10:15:00'
    ]
]
```

**Usage**:
```php
$trends = $statsManager->getErrorTrends(7);

foreach ($trends as $error) {
    echo "{$error['error_type']}: {$error['occurrence_count']} times\n";
    echo "  Severity: {$error['max_severity']}\n";
    echo "  Affected {$error['days_affected']} days\n";
}
```

**Sorted By**: occurrence_count DESC

**Notes**:
- Groups errors by type
- Shows severity and frequency
- Useful for identifying patterns

---

### `getAlerts()`

```php
public function getAlerts(string $status = 'triggered', int $limit = 50): array
```

**Purpose**: Retrieve alerts by status.

**Parameters**:
- `$status` (string): Alert status filter ('triggered', 'acknowledged', 'resolved')
- `$limit` (int): Maximum results (default: 50)

**Return**:
```php
[
    [
        'id' => 1,
        'alert_type' => 'skip_rate_drop',
        'alert_name' => 'Skip rate dropped 25%',
        'severity' => 'medium',
        'status' => 'triggered',
        'created_at' => '2024-12-10 10:30:00',
        'acknowledged_at' => null
    ]
]
```

**Status Values**:
- `triggered` - Alert just occurred
- `acknowledged` - Team reviewed
- `resolved` - Issue fixed

**Usage**:
```php
$alerts = $statsManager->getAlerts('triggered');
echo "Active alerts: " . count($alerts) . "\n";

foreach ($alerts as $alert) {
    echo $alert['alert_name'] . " - {$alert['severity']}\n";
}
```

**Sorted By**: created_at DESC

**Notes**:
- Returns most recent first
- Useful for monitoring dashboards
- Limit prevents memory overload

---

## Reporting

### `generateReport()`

```php
public function generateReport(string $format, array $data): string
```

**Purpose**: Generate report in specified format.

**Parameters**:
- `$format` (string): Output format ('json', 'csv', 'html', 'text')
- `$data` (array): Data to report on

**Supported Formats**:
- `json` - JSON format (pretty-printed)
- `csv` - Comma-separated values
- `html` - HTML table format
- `text` - Human-readable text

**Return**:
- String containing formatted report

**Usage**:
```php
$daily = $statsManager->getDailyStatistics(7);

// Export as JSON
$json = $statsManager->generateReport('json', $daily);
file_put_contents('report.json', $json);

// Export as CSV
$csv = $statsManager->generateReport('csv', $daily);
file_put_contents('report.csv', $csv);

// Export as HTML
$html = $statsManager->generateReport('html', $daily);
file_put_contents('report.html', $html);

// Display as text
$text = $statsManager->generateReport('text', $daily);
echo $text;
```

**Exceptions**:
- Throws Exception for unsupported format

**Notes**:
- JSON is pretty-printed for readability
- CSV is Excel-compatible
- HTML includes basic styling
- Text format is log-friendly

---

## Integration with CarSafariScraper

### `getStatisticsManager()`

```php
// In CarSafariScraper
public function getStatisticsManager(): ?StatisticsManager
```

**Purpose**: Access the StatisticsManager instance.

**Return**:
- StatisticsManager instance or null

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$statsManager = $scraper->getStatisticsManager();

if ($statsManager) {
    $daily = $statsManager->getDailyStatistics(7);
}
```

**Notes**:
- Returns null if StatisticsManager failed to initialize
- Always check for null before calling methods

---

### `getStatisticsForDateRange()`

```php
// In CarSafariScraper
public function getStatisticsForDateRange(string $startDate, string $endDate): array
```

**Purpose**: Proxy to StatisticsManager with vendor ID.

**Parameters**:
- `$startDate` (string): Start date
- `$endDate` (string): End date

**Return**:
- Array of statistics records

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$scraper->setVendorId(432);

$stats = $scraper->getStatisticsForDateRange('2024-12-01', '2024-12-31');
```

**Notes**:
- Automatically uses the scraper's vendor ID
- Handles null StatisticsManager gracefully

---

### `getDailyStatistics()`

```php
// In CarSafariScraper
public function getDailyStatistics(int $days = 30): array
```

**Purpose**: Get daily summary with scraper's vendor ID.

**Parameters**:
- `$days` (int): Number of days

**Return**:
- Array of daily summaries

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$daily = $scraper->getDailyStatistics(30);
```

**Notes**:
- Automatically filtered to scraper's vendor ID

---

### `getWeeklyReport()`

```php
// In CarSafariScraper
public function getWeeklyReport(string $weekStartDate): array
```

**Purpose**: Get weekly report with scraper's vendor ID.

**Parameters**:
- `$weekStartDate` (string): Week start date (Monday)

**Return**:
- Weekly summary array

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$report = $scraper->getWeeklyReport('2024-12-09');
```

---

### `getMonthlyReport()`

```php
// In CarSafariScraper
public function getMonthlyReport(int $year, int $month): array
```

**Purpose**: Get monthly report with scraper's vendor ID.

**Parameters**:
- `$year` (int): Year
- `$month` (int): Month (1-12)

**Return**:
- Monthly summary array

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$report = $scraper->getMonthlyReport(2024, 12);
```

---

### `getErrorTrends()`

```php
// In CarSafariScraper
public function getErrorTrends(int $days = 7): array
```

**Purpose**: Get error trends with scraper's vendor ID.

**Parameters**:
- `$days` (int): Days to analyze

**Return**:
- Array of error trend records

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$trends = $scraper->getErrorTrends(7);
```

---

### `getAlerts()`

```php
// In CarSafariScraper
public function getAlerts(string $status = 'triggered'): array
```

**Purpose**: Get alerts with optional status filter.

**Parameters**:
- `$status` (string): Alert status

**Return**:
- Array of alert records

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$alerts = $scraper->getAlerts('triggered');
```

---

### `generateReport()`

```php
// In CarSafariScraper
public function generateReport(string $format, array $data, string $outputFile = null): string
```

**Purpose**: Generate and optionally save a report.

**Parameters**:
- `$format` (string): Report format
- `$data` (array): Data to report
- `$outputFile` (string, optional): File path to save

**Return**:
- Formatted report string

**Usage**:
```php
$scraper = new CarSafariScraper($config);
$daily = $scraper->getDailyStatistics(7);

// Generate and save
$html = $scraper->generateReport('html', $daily, 'weekly-report.html');

// Just generate
$json = $scraper->generateReport('json', $daily);
```

**Notes**:
- Automatically logs file save
- Throws Exception if StatisticsManager unavailable

---

## Complete Example Usage

```php
<?php
require_once 'config/config.php';
require_once 'autoload.php';

use CarVendors\Scrapers\StatisticsManager;

// Initialize scraper
$scraper = new CarSafariScraper($config);
$scraper->setVendorId(432);

// Run the scrape (statistics automatically tracked)
$result = $scraper->runWithCarSafari();

if ($result['success']) {
    // Get statistics manager
    $statsManager = $scraper->getStatisticsManager();
    
    // Current run statistics
    $current = $statsManager->getCurrentStatistics();
    echo "Current run: {$current['vehicles_found']} vehicles\n";
    echo "Skip rate: {$current['skip_percentage']}%\n";
    
    // Historical data
    $daily = $scraper->getDailyStatistics(30);
    echo "Last 30 days: " . count($daily) . " daily records\n";
    
    // Weekly analysis
    $weekStart = date('Y-m-d', strtotime('last Monday'));
    $weekly = $scraper->getWeeklyReport($weekStart);
    echo "This week: {$weekly['total_vehicles']} vehicles\n";
    
    // Error analysis
    $errors = $scraper->getErrorTrends(7);
    if (!empty($errors)) {
        echo "Recent errors:\n";
        foreach ($errors as $error) {
            echo "  {$error['error_type']}: {$error['occurrence_count']}x\n";
        }
    }
    
    // Export reports
    $monthly = $scraper->getMonthlyReport(2024, 12);
    $scraper->generateReport('html', [$monthly], 'december-report.html');
    $scraper->generateReport('csv', $daily, 'daily-report.csv');
}
?>
```

---

## Performance Characteristics

| Operation | Time | Notes |
|-----------|------|-------|
| Initialize | <1ms | Minimal setup |
| Record action | <1ms | Per call |
| Record error | <2ms | Per error |
| Finalize | 10-50ms | Calculate metrics |
| Save to DB | 50-150ms | Batch inserts |
| Date range query | 10-50ms | Indexed query |
| Weekly report | 50-100ms | Aggregation |
| Monthly report | 100-200ms | Complex query |
| Generate JSON | 10-50ms | Serialization |

---

## Error Handling

All methods include error handling:

```php
try {
    $stats->finalizeStatistics('completed');
    $id = $stats->saveStatistics();
} catch (Exception $e) {
    echo "Statistics save failed: " . $e->getMessage();
    // Scrape can continue, statistics just not saved
}
```

Graceful degradation: If StatisticsManager fails, scraper continues without statistics tracking.

---

## Database Schema Reference

See `sql/02_PHASE_5_STATISTICS_TABLES.sql` for complete schema including:
- `scraper_statistics` - Main statistics table
- `scraper_statistics_daily` - Daily summaries
- `scraper_statistics_trends` - Trend analysis
- `scraper_error_log` - Error details
- `scraper_alerts` - Alert tracking
- `scraper_config` - Configuration

