# 🗄️ Database Schema Changes & SQL Migrations

## Overview

This document lists all SQL database changes required to run the CarVendors Scraper on your cPanel/production environment.

**Database Target**: CarSafari (or your custom database)
**Tables Affected**: 3 existing + 5 new (optional)
**Breaking Changes**: None - all changes are additive (backward compatible)

---

## ⚡ Quick Start: Apply All Changes

### Via cPanel phpMyAdmin:
1. Login to cPanel → MySQL Databases → phpMyAdmin
2. Select your database (e.g., `yourprefix_carsafari`)
3. Click "SQL" tab
4. Copy & paste **MIGRATION 1** below, then Execute
5. Repeat for **MIGRATION 2**, **MIGRATION 3**, etc.

### Via SSH Terminal:
```bash
# Login to your cPanel server
ssh username@yourdomain.com

# Download migrations (if not in your project)
cd ~/public_html/carvendors-scraper

# Apply Migration 1
mysql -u yourprefix_caruser -p yourprefix_carsafari < sql/01_ADD_UNIQUE_REG_NO.sql
# (Enter your database password when prompted)

# Apply Migration 2 (optional, for statistics)
mysql -u yourprefix_caruser -p yourprefix_carsafari < sql/02_PHASE_5_STATISTICS_TABLES.sql

# Apply Migration 3 (optional, for CarCheck caching)
mysql -u yourprefix_caruser -p yourprefix_carsafari < sql/03_CARCHECK_CACHE_TABLES.sql
```

---

## 📋 MIGRATION 1: Essential Changes (REQUIRED)

### Purpose
- Add unique index on `reg_no` (prevents duplicate vehicles)
- Add `data_hash` column (detects vehicle data changes)
- Improve query performance with indexes
- Ensure UTF-8 encoding for proper text handling

### SQL Code

```sql
-- ============================================
-- MIGRATION 1: Core Schema Updates
-- Date: 2025-12-04
-- ============================================

-- Step 1: Add UNIQUE constraint on reg_no
-- This prevents duplicate vehicle registration numbers
-- and enables ON DUPLICATE KEY UPDATE to work correctly
ALTER TABLE gyc_vehicle_info 
DROP INDEX IF EXISTS idx_reg_no,
ADD UNIQUE INDEX idx_reg_no (reg_no);

-- Step 2: Add performance indexes
-- For faster filtering by vendor and status
ALTER TABLE gyc_vehicle_info 
ADD INDEX idx_vendor_status (vendor_id, active_status);

-- Step 3: Add data_hash column
-- Used to detect if a vehicle's data has changed (prevents unnecessary updates)
ALTER TABLE gyc_vehicle_info 
ADD COLUMN data_hash VARCHAR(32) NULL COMMENT 'MD5 hash of vehicle data for change detection' AFTER vehicle_url;

-- Step 4: Ensure UTF-8 encoding
-- Handles special characters (é, ü, ñ, etc.) and emoji properly
ALTER TABLE gyc_vehicle_info 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE gyc_vehicle_attribute 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE gyc_product_images 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================
-- Verification (run these to confirm)
-- ============================================

-- Check if data_hash column was added
SHOW COLUMNS FROM gyc_vehicle_info WHERE Field='data_hash';
-- Expected: One row with Field='data_hash', Type='varchar(32)'

-- Check if unique index on reg_no exists
SHOW INDEX FROM gyc_vehicle_info WHERE Key_name='idx_reg_no';
-- Expected: One row with Key_name='idx_reg_no', Non_unique=0

-- Check character set
SELECT TABLE_NAME, TABLE_COLLATION 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME IN ('gyc_vehicle_info', 'gyc_vehicle_attribute', 'gyc_product_images');
-- Expected: All should show 'utf8mb4_unicode_ci'
```

### What Changed

| Table | Change | Purpose |
|-------|--------|---------|
| `gyc_vehicle_info` | + `UNIQUE INDEX idx_reg_no (reg_no)` | Prevent duplicate vehicles with same registration |
| `gyc_vehicle_info` | + `INDEX idx_vendor_status` | Speed up queries filtering by vendor + status |
| `gyc_vehicle_info` | + `data_hash VARCHAR(32)` | Detect if vehicle data has changed |
| All 3 tables | CHARSET=utf8mb4 | Support special characters & international text |

### Impact
✅ **Non-breaking** - No data loss, only adds columns/indexes  
✅ **Required** - Must be applied before running scraper  
⏱️ **Duration** - ~5 seconds on small databases, ~30 seconds on large databases  

---

## 📊 MIGRATION 2: Statistics Tables (Optional)

### Purpose
- Track scraper performance over time
- Log execution times, vehicle counts, errors
- Monitor success rates and trends

### SQL Code

```sql
-- ============================================
-- MIGRATION 2: Statistics & Monitoring Tables
-- Date: 2025-12-04
-- ============================================

-- Table 1: Daily scrape statistics
CREATE TABLE IF NOT EXISTS scraper_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_date DATE NOT NULL,
    source VARCHAR(50) NOT NULL COMMENT 'e.g., carsafari, vehiclesupply, etc.',
    total_found INT DEFAULT 0 COMMENT 'Total vehicles found on website',
    total_inserted INT DEFAULT 0 COMMENT 'New vehicles added to DB',
    total_updated INT DEFAULT 0 COMMENT 'Existing vehicles refreshed',
    total_skipped INT DEFAULT 0 COMMENT 'Vehicles skipped (e.g., duplicates)',
    images_stored INT DEFAULT 0 COMMENT 'Total images downloaded',
    errors INT DEFAULT 0 COMMENT 'Parse/network errors',
    execution_time DECIMAL(10,2) COMMENT 'Total runtime in seconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX unique_run (run_date, source),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table 2: Hourly trends (for analytics)
CREATE TABLE IF NOT EXISTS scraper_statistics_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,
    source VARCHAR(50) NOT NULL,
    avg_execution_time DECIMAL(10,2),
    total_vehicles_found INT,
    total_vehicles_inserted INT,
    success_rate DECIMAL(5,2) COMMENT 'Percentage (0-100)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX unique_daily (stat_date, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table 3: Error tracking
CREATE TABLE IF NOT EXISTS scraper_error_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(100) COMMENT 'e.g., connection_timeout, parse_error',
    error_message TEXT,
    vehicle_url VARCHAR(500),
    scraper_source VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_source (scraper_source),
    INDEX idx_error_type (error_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Verification
-- ============================================

-- Check new tables exist
SHOW TABLES LIKE 'scraper_%';
-- Expected: 3 rows (scraper_statistics, scraper_statistics_daily, scraper_error_log)

-- Check columns in scraper_statistics
SHOW COLUMNS FROM scraper_statistics;
-- Expected: 12 columns (id, run_date, source, total_found, etc.)
```

### What Changed

| Table | Purpose | Rows/Day |
|-------|---------|----------|
| `scraper_statistics` | Daily execution stats | 1-2 |
| `scraper_statistics_daily` | Daily aggregation for trends | 1-2 |
| `scraper_error_log` | Error tracking | 0-50 |

### Impact
✅ **Optional** - Scraper works without these  
✅ **Non-breaking** - Purely additive  
📊 **Useful** - Helps monitor scraper health over time  

### Query Examples

```sql
-- View last 10 scrapes
SELECT run_date, source, total_found, total_inserted, errors, execution_time 
FROM scraper_statistics 
ORDER BY run_date DESC 
LIMIT 10;

-- View success rate over last 7 days
SELECT 
    stat_date, 
    source, 
    total_vehicles_found, 
    total_vehicles_inserted, 
    success_rate 
FROM scraper_statistics_daily 
WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY stat_date DESC;

-- View recent errors
SELECT error_type, COUNT(*) as count, MAX(created_at) as last_error
FROM scraper_error_log 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY error_type
ORDER BY count DESC;
```

---

## 🔐 MIGRATION 3: CarCheck Cache (Optional)

### Purpose
- Cache vehicle lookups from CarCheck API
- Reduce redundant API calls
- Speed up multi-source enrichment

### SQL Code

```sql
-- ============================================
-- MIGRATION 3: CarCheck Cache Tables
-- Date: 2025-12-04
-- ============================================

-- Table 1: CarCheck API response cache
CREATE TABLE IF NOT EXISTS carcheck_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration VARCHAR(20) NOT NULL UNIQUE,
    api_response JSON,
    status VARCHAR(50) COMMENT 'valid, invalid, not_found, error',
    hits INT DEFAULT 1 COMMENT 'How many times this record was used',
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL COMMENT 'When to refresh this cache',
    INDEX idx_registration (registration),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table 2: Cache statistics
CREATE TABLE IF NOT EXISTS carcheck_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,
    total_lookups INT DEFAULT 0,
    cache_hits INT DEFAULT 0,
    cache_misses INT DEFAULT 0,
    api_calls_saved INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX unique_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table 3: API errors
CREATE TABLE IF NOT EXISTS carcheck_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration VARCHAR(20),
    error_message TEXT,
    error_code VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_registration (registration),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Verification
-- ============================================

SHOW TABLES LIKE 'carcheck_%';
-- Expected: 3 rows (carcheck_cache, carcheck_statistics, carcheck_errors)
```

### What Changed

| Table | Purpose |
|-------|---------|
| `carcheck_cache` | Stores CarCheck API responses to avoid repeated lookups |
| `carcheck_statistics` | Tracks cache hit rate and API savings |
| `carcheck_errors` | Logs failed CarCheck lookups |

### Impact
✅ **Optional** - Only used if integrating with CarCheck API  
⚡ **Performance** - Can save 10-50 API calls per full scrape  

---

## 🚀 Summary: Which Migrations to Apply?

| Migration | Required? | Purpose | Time |
|-----------|-----------|---------|------|
| **MIGRATION 1** | ✅ **YES** | Unique index, data_hash, UTF-8 | < 1 min |
| **MIGRATION 2** | ⏳ Optional | Statistics & monitoring | < 1 min |
| **MIGRATION 3** | ⏳ Optional | CarCheck API caching | < 1 min |

### Recommended Setup

**For First-Time Installation:**
```bash
# Apply Migration 1 (REQUIRED)
mysql -u user -p db < sql/01_ADD_UNIQUE_REG_NO.sql

# Apply Migration 2 (RECOMMENDED - nice to have)
mysql -u user -p db < sql/02_PHASE_5_STATISTICS_TABLES.sql

# Skip Migration 3 unless using CarCheck API
```

**For Existing Installation:**
```bash
# Just add Migration 1 if not already applied
mysql -u user -p db < sql/01_ADD_UNIQUE_REG_NO.sql

# Everything else is optional and won't break existing data
```

---

## 📝 Verification Checklist

After applying migrations, verify with these queries:

```sql
-- Check 1: Core columns exist
SELECT COUNT(*) as core_columns 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME='gyc_vehicle_info' 
AND COLUMN_NAME IN ('data_hash', 'vendor_id', 'reg_no', 'color');
-- Expected: 4

-- Check 2: Unique index on reg_no
SELECT COUNT(*) as unique_indexes 
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_NAME='gyc_vehicle_info' 
AND COLUMN_NAME='reg_no' 
AND NON_UNIQUE=0;
-- Expected: 1

-- Check 3: Character set is UTF-8
SELECT COUNT(*) as utf8_tables 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_NAME IN ('gyc_vehicle_info', 'gyc_vehicle_attribute', 'gyc_product_images')
AND TABLE_COLLATION LIKE 'utf8mb4%';
-- Expected: 3

-- Check 4: Total vehicles in database
SELECT COUNT(*) as total_vehicles FROM gyc_vehicle_info;
-- Expected: 82 (or your current count)

-- Check 5: Sample vehicle with all fields
SELECT 
    reg_no, 
    color, 
    data_hash,
    vendor_id, 
    active_status 
FROM gyc_vehicle_info 
LIMIT 1;
-- Expected: One complete row with color not NULL
```

---

## ⚠️ Troubleshooting

### Error: "Duplicate key value on reg_no"
**Cause**: Your database already has duplicate registration numbers  
**Fix**:
```sql
-- Find duplicates
SELECT reg_no, COUNT(*) as cnt FROM gyc_vehicle_info GROUP BY reg_no HAVING cnt > 1;

-- Keep one, delete others (BACKUP FIRST!)
DELETE vi1 FROM gyc_vehicle_info vi1
INNER JOIN gyc_vehicle_info vi2 
WHERE vi1.id > vi2.id AND vi1.reg_no = vi2.reg_no;

-- Then apply migration 1
```

### Error: "Syntax Error" in SQL
**Cause**: Copy/paste issue or SQL compatibility  
**Fix**:
1. Copy SQL code directly from this file (not a screenshot)
2. Paste into phpMyAdmin "SQL" tab
3. Click "Execute" (not "Go")

### Error: "Unknown column 'data_hash'"
**Cause**: Migration 1 wasn't applied  
**Fix**:
```sql
ALTER TABLE gyc_vehicle_info 
ADD COLUMN data_hash VARCHAR(32) NULL AFTER vehicle_url;
```

### Error: "Permission denied" via SSH
**Cause**: Database user doesn't have permissions  
**Fix**:
```bash
# In cPanel MySQL Databases, ensure user has ALL permissions on database
# Or re-grant permissions:
mysql -u root -p
GRANT ALL PRIVILEGES ON yourdb.* TO 'youruser'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 📚 Additional Resources

- **Full cPanel Setup**: See `README.md` section "cPanel Full Setup Guide"
- **Scraper Code**: `CarSafariScraper.php` and `CarScraper.php`
- **Configuration**: `config.php`
- **Logs**: `logs/scraper_*.log` (created after first run)

---

**Last Updated**: December 5, 2025  
**Version**: 2.0  
**Status**: Production Ready
