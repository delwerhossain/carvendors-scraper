# Phase 5: Enhanced Statistics & Reporting

## Overview

Phase 5 adds comprehensive statistics tracking, historical data persistence, and automated reporting capabilities to the scraper.

**Status**: PLANNED (Ready for implementation)

---

## Features

### 1. Statistics Database Table

New table to persist scrape statistics:

```sql
CREATE TABLE scraper_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scrape_date DATE NOT NULL,
    scrape_time TIME NOT NULL,
    vendor_id INT NOT NULL,
    
    -- Processing stats
    vehicles_found INT NOT NULL,
    vehicles_inserted INT NOT NULL,
    vehicles_updated INT NOT NULL,
    vehicles_skipped INT NOT NULL,
    vehicles_deactivated INT NOT NULL,
    
    -- Processing metrics
    skip_percentage DECIMAL(5,2) NOT NULL,
    processing_time_seconds INT NOT NULL,
    
    -- Data stats
    total_images INT NOT NULL,
    images_stored INT NOT NULL,
    
    -- Quality metrics
    error_count INT NOT NULL,
    avg_processing_per_vehicle DECIMAL(6,3) NOT NULL,
    
    -- Status
    status VARCHAR(20) NOT NULL,  -- completed, failed, partial
    error_message TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_vendor_date (vendor_id, scrape_date),
    INDEX idx_status (status),
    INDEX idx_scrape_date (scrape_date)
);
```

### 2. Enhanced Stats Class

New `StatisticsManager` class to handle persistence and reporting:

```php
class StatisticsManager
{
    /**
     * Save scrape statistics to database
     */
    public function saveStatistics(array $stats): bool
    
    /**
     * Get statistics for date range
     */
    public function getStatisticsForDateRange(
        DateTime $startDate, 
        DateTime $endDate,
        int $vendorId = null
    ): array
    
    /**
     * Get daily statistics
     */
    public function getDailyStatistics(
        int $daysBack = 30,
        int $vendorId = null
    ): array
    
    /**
     * Generate weekly report
     */
    public function generateWeeklyReport(
        int $vendorId = null
    ): array
    
    /**
     * Generate monthly report
     */
    public function generateMonthlyReport(
        int $vendorId = null
    ): array
    
    /**
     * Generate error trends
     */
    public function getErrorTrends(
        int $daysBack = 30
    ): array
}
```

### 3. Statistics Display

Enhanced statistics output with trends and comparisons:

```
========== EXTENDED OPTIMIZATION REPORT ==========

PROCESSING EFFICIENCY:
  Found:          81
  Inserted:        0
  Updated:         0
  Skipped:        81
  Skip Rate:    100.0%
  
COMPARISON TO PREVIOUS RUN:
  Previous Skip Rate: 89.6%
  Improvement:        +10.4%
  
DATABASE OPERATIONS:
  Published:        81
  Images:            0
  Errors:            0
  
PERFORMANCE METRICS:
  Duration:        45s
  Rate:           1.8 vehicles/sec
  Avg per Vehicle: 0.56s
  
MONTHLY TRENDS (30 days):
  Avg Skip Rate:  93.2%
  Total Vehicles: 2,187
  Total Inserts:    128
  Total Updates:    243
  
ERROR ANALYSIS (30 days):
  Total Errors:      3
  Error Rate:      0.14%
  Most Common: Timeout (2 times)
  
STORAGE:
  JSON Files:        60
  Log Files:          8
  Image Files:      650
  
================================================
```

### 4. Statistics Methods

New methods in CarScraper and CarSafariScraper:

```php
// In CarScraper.php
protected function calculateStatisticsMetrics(): array

protected function compareWithPreviousScrape(): array

protected function detectAnomalies(): array

protected function generateDetailedReport(): array

// In CarSafariScraper.php
public function saveStatisticsToDatabase(array $stats): bool

public function getHistoricalStatistics(int $daysBack = 30): array

public function generateAutomatedReport(string $format = 'text'): string
```

### 5. Report Formats

Support multiple output formats:

**Text Report** (Default)
```
Simple, human-readable format for console/logs
```

**JSON Report**
```json
{
  "date": "2024-12-13",
  "stats": { ... },
  "trends": { ... },
  "recommendations": [ ... ]
}
```

**CSV Report**
```
date,vehicles_found,inserted,updated,skipped,duration
2024-12-13,81,0,0,81,45
2024-12-12,81,2,0,79,120
```

**HTML Report**
```html
<table>
  <tr><td>Date</td><td>Found</td><td>Skip %</td>...</tr>
  ...
</table>
```

### 6. Automated Reporting

Email reports on schedule:

```php
// Daily summary email
0 6 * * * php bin/generate-daily-report.php --email admin@example.com

// Weekly summary
0 9 * * MON php bin/generate-weekly-report.php --email admin@example.com

// Monthly analysis
0 9 1 * * php bin/generate-monthly-report.php --email admin@example.com
```

### 7. Dashboard Data

REST API for dashboard integration:

```php
// GET /api/statistics/daily
// GET /api/statistics/weekly
// GET /api/statistics/monthly
// GET /api/statistics/trends?days=30&vendor=432
```

---

## Implementation Checklist

### Database Setup
- [ ] Create `scraper_statistics` table
- [ ] Create indexes for performance
- [ ] Add default records for historical data

### Code Changes
- [ ] Create `StatisticsManager` class
- [ ] Add stats persistence to CarSafariScraper
- [ ] Add report generation methods
- [ ] Add anomaly detection logic
- [ ] Add email notification support

### Testing
- [ ] Test statistics persistence
- [ ] Test report generation
- [ ] Test trend calculations
- [ ] Test email delivery
- [ ] Test API endpoints

### Documentation
- [ ] Update API reference
- [ ] Create statistics guide
- [ ] Create report format documentation
- [ ] Create dashboard integration guide

### Deployment
- [ ] Execute database migration
- [ ] Deploy new classes
- [ ] Update cron jobs
- [ ] Configure email settings
- [ ] Test end-to-end

---

## Expected Benefits

✅ **Better Monitoring**: See trends and patterns  
✅ **Early Detection**: Identify issues before they escalate  
✅ **Performance Tracking**: Monitor scraper efficiency over time  
✅ **Automated Alerts**: Email when something goes wrong  
✅ **Historical Data**: Compare current vs previous runs  
✅ **Actionable Insights**: Get recommendations for improvement  

---

## Example Statistics Over Time

```
Date       | Found | Inserted | Updated | Skipped | Skip% | Duration
-----------|-------|----------|---------|---------|-------|----------
2024-12-13 |  81   |    0     |    0    |   81    | 100%  |   45s
2024-12-12 |  81   |    2     |    0    |   79    |  97%  |   90s
2024-12-11 |  81   |    0     |    3    |   78    |  96%  |  120s
2024-12-10 |  80   |    1     |    1    |   78    |  98%  |   80s
2024-12-09 |  81   |    0     |    0    |   81    | 100%  |   45s

Weekly Average:
  Skip Rate: 96.2%
  Avg Duration: 76 seconds
  Vehicles Found: 81
  Total Inserts: 3
  Total Updates: 4
```

---

## Anomaly Detection Examples

```
⚠️  ANOMALIES DETECTED:

1. Skip Rate Dropped
   Previous: 96.2%
   Current: 75.3%
   Cause: Likely multiple prices changed

2. Processing Time Increased
   Previous: 45s
   Current: 180s
   Cause: May be fetching images for many vehicles

3. Error Rate Spike
   Previous: 0.14%
   Current: 2.3%
   Cause: Potential network or parsing issues

Recommendations:
• Check if dealer website structure changed
• Review recent price updates
• Monitor error logs for details
```

---

## Database Migration SQL

```sql
-- Create statistics table
CREATE TABLE scraper_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scrape_date DATE NOT NULL,
    scrape_time TIME NOT NULL,
    vendor_id INT NOT NULL,
    vehicles_found INT NOT NULL,
    vehicles_inserted INT NOT NULL,
    vehicles_updated INT NOT NULL,
    vehicles_skipped INT NOT NULL,
    vehicles_deactivated INT NOT NULL,
    skip_percentage DECIMAL(5,2) NOT NULL,
    processing_time_seconds INT NOT NULL,
    total_images INT NOT NULL,
    images_stored INT NOT NULL,
    error_count INT NOT NULL,
    avg_processing_per_vehicle DECIMAL(6,3) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vendor_date (vendor_id, scrape_date),
    INDEX idx_status (status),
    INDEX idx_scrape_date (scrape_date)
);

-- Populate with historical data (from logs)
-- This would be implemented as part of Phase 5
```

---

## Configuration for Phase 5

```php
// In config/config.php
'statistics' => [
    'enabled' => true,
    'retention_days' => 365,
    'auto_cleanup' => true,
    
    'reports' => [
        'enabled' => true,
        'email_recipients' => ['admin@example.com'],
        'formats' => ['text', 'json', 'html'],
    ],
    
    'alerts' => [
        'enabled' => true,
        'skip_rate_threshold' => 50,      // Alert if < 50%
        'error_rate_threshold' => 5,      // Alert if > 5%
        'processing_time_threshold' => 600,  // Alert if > 10 min
    ],
];
```

---

## Timeline

**Phase 5 Estimated**: 2-3 hours

- Database migration: 15 min
- StatisticsManager class: 60 min
- Report generation: 45 min
- Testing & validation: 30 min

---

## Integration Points

Phase 5 will integrate with:
- Phase 1-3: Core scraping logic (no changes needed)
- Phase 4: Use new project structure
- Future dashboards: Provide API endpoints
- Email systems: For automated reports

---

## Future Extensions (Phase 6+)

- [ ] Web dashboard with charts
- [ ] Real-time monitoring
- [ ] Slack/Teams notifications
- [ ] Database analytics
- [ ] Performance optimization suggestions
- [ ] Comparative analysis (vendor vs industry)

---

## Summary

**Phase 5 delivers**:
1. Database table for statistics persistence
2. StatisticsManager class for data handling
3. Enhanced reporting with multiple formats
4. Automated report generation
5. Anomaly detection system
6. REST API for dashboard integration

**Key Metrics**:
- 95% reduction in manual monitoring
- Early detection of issues
- Historical trend analysis
- Automated alerting

**Status**: Ready to implement after Phase 4

---

*Enhanced Statistics & Reporting Plan*  
*Phase 5 of 5*  
*December 13, 2024*
