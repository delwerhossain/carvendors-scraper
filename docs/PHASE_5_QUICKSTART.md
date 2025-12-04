# Phase 5: Quick Start Guide

Get up and running with statistics tracking in 5 minutes.

---

## Step 1: Run Database Migration

```bash
cd /path/to/carvendors-scraper
mysql carsafari < sql/02_PHASE_5_STATISTICS_TABLES.sql
```

**Verify**:
```bash
mysql carsafari -e "SHOW TABLES LIKE 'scraper_%';"
```

Expected output: 6 tables (scraper_statistics, scraper_statistics_daily, etc.)

---

## Step 2: That's It! Statistics Now Active

The scraper now automatically tracks all statistics:

```php
require_once 'config/config.php';
require_once 'autoload.php';

$scraper = new CarSafariScraper($config);
$result = $scraper->runWithCarSafari();

// Statistics automatically saved after run!
```

---

## Step 3: Query Your Statistics

### View Latest Run

```php
$statsManager = $scraper->getStatisticsManager();
$current = $statsManager->getCurrentStatistics();

echo "Vehicles found: {$current['vehicles_found']}\n";
echo "Skip rate: {$current['skip_percentage']}%\n";
echo "Processing time: {$current['processing_time_formatted']}\n";
```

### Last 30 Days Summary

```php
$daily = $scraper->getDailyStatistics(30);

foreach ($daily as $day) {
    echo $day['scrape_date'] . ": " . $day['total_runs'] . " runs\n";
}
```

### This Week's Report

```php
$weekStart = date('Y-m-d', strtotime('last Monday'));
$weekly = $scraper->getWeeklyReport($weekStart);

echo "Week: {$weekly['total_vehicles']} vehicles processed\n";
echo "Skip rate: {$weekly['avg_skip_rate']}%\n";
```

### This Month's Report

```php
$monthly = $scraper->getMonthlyReport(2024, 12);

echo "December: {$monthly['total_runs']} total runs\n";
echo "Success rate: " . 
  round(($monthly['successful_runs']/$monthly['total_runs'])*100, 1) . "%\n";
```

---

## Step 4: Export Reports

### As JSON

```php
$daily = $scraper->getDailyStatistics(7);
$json = $scraper->generateReport('json', $daily);
file_put_contents('report.json', $json);
```

### As CSV

```php
$daily = $scraper->getDailyStatistics(7);
$csv = $scraper->generateReport('csv', $daily);
file_put_contents('report.csv', $csv);
// Open in Excel!
```

### As HTML

```php
$monthly = $scraper->getMonthlyReport(2024, 12);
$html = $scraper->generateReport('html', [$monthly]);
file_put_contents('report.html', $html);
// Open in browser!
```

---

## Step 5: Monitor Errors & Anomalies

### Recent Error Patterns

```php
$errors = $scraper->getErrorTrends(7);

foreach ($errors as $error) {
    echo "{$error['error_type']}: {$error['occurrence_count']} times\n";
    echo "  Severity: {$error['max_severity']}\n";
    echo "  Last seen: {$error['most_recent']}\n";
}
```

### Active Alerts

```php
$alerts = $scraper->getAlerts('triggered');

if (!empty($alerts)) {
    echo "Active alerts: " . count($alerts) . "\n";
    foreach ($alerts as $alert) {
        echo "- {$alert['alert_name']}\n";
    }
}
```

---

## Common Tasks

### Export Last Week's Data

```php
$weekStart = date('Y-m-d', strtotime('-1 week Monday'));
$weekEnd = date('Y-m-d', strtotime('-1 week Sunday'));
$stats = $scraper->getStatisticsForDateRange($weekStart, $weekEnd);

$csv = $scraper->generateReport('csv', $stats);
file_put_contents('last-week.csv', $csv);
```

### Email Weekly Report

```php
$weekStart = date('Y-m-d', strtotime('last Monday'));
$weekly = $scraper->getWeeklyReport($weekStart);
$html = $scraper->generateReport('html', [$weekly]);

mail('admin@example.com',
    'Weekly Scraper Report',
    $html,
    "Content-Type: text/html\r\n"
);
```

### Dashboard API Endpoint

```php
// In api/statistics.php
header('Content-Type: application/json');

$scraper = new CarSafariScraper($config);
$daily = $scraper->getDailyStatistics(30);

echo json_encode([
    'status' => 'success',
    'data' => $daily,
    'timestamp' => date('Y-m-d H:i:s')
]);
```

### Monitor for Issues

```php
$current = $statsManager->getCurrentStatistics();

if ($current['skip_percentage'] < 50) {
    // Alert: More vehicles being processed than normal
    echo "WARNING: Skip rate dropped to {$current['skip_percentage']}%\n";
}

if ($current['error_count'] > 5) {
    // Alert: More errors than normal
    echo "WARNING: {$current['error_count']} errors detected\n";
}

if (isset($current['anomaly_types']) && !empty($current['anomaly_types'])) {
    echo "ANOMALIES DETECTED: {$current['anomaly_types']}\n";
}
```

---

## Database Queries

### Check Statistics Table

```sql
SELECT COUNT(*) as total_runs FROM scraper_statistics;

SELECT scrape_date, COUNT(*) as runs 
FROM scraper_statistics 
GROUP BY scrape_date 
ORDER BY scrape_date DESC 
LIMIT 7;
```

### View Error Log

```sql
SELECT error_type, COUNT(*) as count 
FROM scraper_error_log 
WHERE first_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY error_type 
ORDER BY count DESC;
```

### Find Anomalies

```sql
SELECT scrape_datetime, anomaly_types 
FROM scraper_statistics 
WHERE has_anomalies = TRUE 
ORDER BY scrape_datetime DESC 
LIMIT 10;
```

### Daily Summary

```sql
SELECT * FROM scraper_statistics_daily 
WHERE vendor_id = 432 
ORDER BY scrape_date DESC 
LIMIT 30;
```

---

## Integration with Cron

Your cron job now automatically saves statistics:

```bash
*/6 * * * * cd /home/user/carvendors-scraper && \
  php scrape-carsafari.php >> logs/cron.log 2>&1
```

After each run, statistics are automatically saved to the database. View them anytime:

```bash
# Check last run
mysql carsafari -e \
  "SELECT scrape_date, vehicles_found, skip_percentage FROM scraper_statistics ORDER BY scrape_date DESC LIMIT 1;"
```

---

## Configuration

Adjust thresholds in the database:

```sql
-- Change alert thresholds
UPDATE scraper_config 
SET config_value = '30' 
WHERE config_key = 'alert_skip_rate_drop';

-- View current settings
SELECT config_key, config_value 
FROM scraper_config 
WHERE config_category = 'thresholds';
```

---

## Troubleshooting

### Statistics not saving?

Check database connection:
```php
try {
    $statsManager = $scraper->getStatisticsManager();
    if (!$statsManager) {
        echo "StatisticsManager not initialized\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### No historical data?

Make sure migration was run:
```bash
mysql carsafari -e "SHOW TABLES LIKE 'scraper_statistics';"
```

If not showing, re-run migration:
```bash
mysql carsafari < sql/02_PHASE_5_STATISTICS_TABLES.sql
```

### Permissions error?

Grant permissions:
```sql
GRANT INSERT, UPDATE, SELECT ON carsafari.scraper_* 
TO 'db_user'@'localhost';
```

---

## Next Steps

1. **Monitor Performance**: Check statistics daily
2. **Set Alerts**: Configure thresholds for your baseline
3. **Export Reports**: Generate weekly reports
4. **Track Trends**: Monitor skip rates and error patterns
5. **Optimize**: Use statistics to improve performance

---

## Full Documentation

- **Implementation Details**: `docs/PHASE_5_IMPLEMENTATION.md`
- **API Reference**: `docs/PHASE_5_API_REFERENCE.md`
- **Database Schema**: `sql/02_PHASE_5_STATISTICS_TABLES.sql`

---

**That's it! You're now tracking comprehensive scraper statistics.** 📊
