# Live DB Migration Guide - carsafari (Production)

**⚠️ IMPORTANT**: For complete deployment with all SQL queries, see [CPANEL_DEPLOYMENT_SQL.md](CPANEL_DEPLOYMENT_SQL.md)

Safe SQL migration steps to prepare cPanel live database for CarVendors scraper with health-gated cleanup.

**Database**: `carsafari` (production live database)  
**Environment**: MariaDB 10.6.23 on cPanel  
**Purpose**: Enable safe daily refresh with health gates + statistics tracking  
**Vendor**: 432 (systonautosltd.co.uk)

---

## Quick Summary

The `daily_refresh.php` script performs **5 phases**:

1. **Phase 0 (Cleanup)**: Delete ALL vendor 432 data IF both gates pass
2. **Phase 1 (Scrape)**: Fetch data from systonautosltd.co.uk (70-80 vehicles)
3. **Phase 2 (Hash Detection)**: Skip unchanged vehicles (100% efficiency)
4. **Phase 3 (Publish)**: Auto-publish new/changed vehicles (active_status=1)
5. **Phase 4 (Stats)**: Record metrics in scraper_statistics table

**Safety Gates** (must both pass before cleanup):
- Success Rate: `(inserted + updated + skipped) / found >= 85%`
- Inventory Ratio: `new_vehicles >= baseline_vehicles * 80%`

If either gate fails → No deletion, alert sent, data preserved.

---

## ✅ Pre-Deployment Checklist

- [ ] Backup live database: `mysqldump -u user -p carsafari > backup_YYYYMMDD.sql`
- [ ] Verify current schema: `DESCRIBE gyc_vehicle_info;`
- [ ] Check vendor_id 432 exists: `SELECT * FROM gyc_vendor_info WHERE id = 432;`
- [ ] Run during low-traffic window (2:00 AM - 5:00 AM)
- [ ] Have SSH/cPanel MySQL access ready

---

## Core Tables (Must Exist)

### gyc_vehicle_info (Main Vehicle Data)
```sql
-- Verify table exists and has correct structure
DESCRIBE gyc_vehicle_info;

-- Expected columns:
-- id (PK INT auto_increment)
-- vendor_id (INT, FK to gyc_vendor_info)
-- reg_no (VARCHAR 255, unique registration)
-- attr_id (INT, FK to gyc_vehicle_attribute)
-- selling_price (INT)
-- mileage (INT)
-- color (VARCHAR 100)
-- color_id (INT)
-- description (TEXT)
-- active_status (ENUM 0,1,2,3,4)
-- created_at (DATETIME)
-- updated_at (DATETIME)
```

### gyc_vehicle_attribute (Vehicle Specs)
```sql
-- Verify table exists
DESCRIBE gyc_vehicle_attribute;

-- Expected columns:
-- id (PK INT)
-- category_id (INT)
-- make_id (INT, FK to gyc_make)
-- model (VARCHAR 255)
-- year (INT)
-- fuel_type (VARCHAR 255)
-- transmission (VARCHAR 255)
-- body_style (VARCHAR 50)
-- engine_size (VARCHAR 255)
```

### gyc_product_images (Vehicle Images)
```sql
-- Verify table exists
DESCRIBE gyc_product_images;

-- Expected columns:
-- id (PK INT)
-- file_name (VARCHAR 255, stores URL references)
-- vechicle_info_id (INT, FK to gyc_vehicle_info)
-- serial (INT, image order: 1,2,3...)
```

---

## New Tables Required

### scraper_statistics (Performance Tracking)
```sql
CREATE TABLE IF NOT EXISTS `scraper_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `vendor_id` int(11) NOT NULL,
  `run_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) DEFAULT 'success',
  `vehicles_found` int(11) DEFAULT 0,
  `vehicles_inserted` int(11) DEFAULT 0,
  `vehicles_updated` int(11) DEFAULT 0,
  `vehicles_skipped` int(11) DEFAULT 0,
  `images_stored` int(11) DEFAULT 0,
  `success_rate` decimal(5,2) DEFAULT 0.00,
  `inventory_ratio` decimal(5,2) DEFAULT 0.00,
  `gates_passed` tinyint(1) DEFAULT 0,
  `duration_seconds` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `stats_json` longtext DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  KEY `vendor_date` (`vendor_id`, `run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Missing Columns (Check & Add If Required)

-- Key columns: id (PK)
-- vechicle_info_id (FK → gyc_vehicle_info.id)
-- file_name (image URL), serial (1,2,3...)
```

---

## 2. Add Missing Required Columns to gyc_vehicle_info

**Purpose**: Add fields needed by the scraper for URL storage and change detection.

```sql
-- Check if columns exist first (safety check)
DESCRIBE gyc_vehicle_info;

-- Add vehicle_url if missing (stores source listing URL)
ALTER TABLE gyc_vehicle_info
ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL AFTER description;

-- Add data_hash if missing (for efficient change detection)
ALTER TABLE gyc_vehicle_info
ADD COLUMN data_hash VARCHAR(64) DEFAULT NULL AFTER updated_at;

-- Verify columns were added
DESCRIBE gyc_vehicle_info;
```

## 2) Create statistics/support tables (if missing)

Needed by the scraper’s `StatisticsManager` and logging.

```sql
CREATE TABLE IF NOT EXISTS scraper_statistics (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT(11) NOT NULL DEFAULT 432,
  run_date DATE NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'completed',
  vehicles_found INT(11) DEFAULT 0,
  vehicles_inserted INT(11) DEFAULT 0,
  vehicles_updated INT(11) DEFAULT 0,
  vehicles_skipped INT(11) DEFAULT 0,
  vehicles_failed INT(11) DEFAULT 0,
  images_stored INT(11) DEFAULT 0,
  requests_made INT(11) DEFAULT 0,
  duration_minutes DECIMAL(10,2) DEFAULT 0.00,
  stats_json TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_run_date (run_date),
  INDEX idx_vendor_date (vendor_id, run_date),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_logs (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(50) NOT NULL DEFAULT 'unknown', -- inserted, updated, skipped, failed
  vehicle_id INT(11) DEFAULT NULL,
  reg_no VARCHAR(50) DEFAULT NULL,
  title VARCHAR(255) DEFAULT NULL,
  price DECIMAL(10,2) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action (action),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_logs (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL DEFAULT 'unknown',
  message TEXT NOT NULL,
  severity VARCHAR(20) NOT NULL DEFAULT 'ERROR',
  timestamp DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type_severity (type, severity),
  INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3) Color palette (ensure canonical IDs 1–22)

Keep the canonical IDs aligned to the expected palette used by the scraper mapping:
```sql
REPLACE INTO gyc_vehicle_color (id, color_name) VALUES
 (1,'Beige'),(2,'Black'),(3,'Blue'),(4,'Bronze'),(5,'Brown'),
 (6,'Burgundy'),(7,'Gold'),(8,'Green'),(9,'Grey'),(10,'Indigo'),
 (11,'Magenta'),(12,'Mcroon'),(13,'Multicolor'),(14,'Navy'),(15,'Orange'),
 (16,'Pink'),(17,'Purple'),(18,'Red'),(19,'-'),(20,'White'),
 (21,'Silver'),(22,'Yellow');
```

Optional: add common spelling/variant rows for UI/search (scraper maps variants in-code to the canonical IDs above):
```sql
INSERT IGNORE INTO gyc_vehicle_color (color_name) VALUES
 ('Gray'),('Charcoal'),('Gunmetal'),('Pearl White'),('Off White'),
 ('Ivory'),('Cream'),('Champagne'),('Teal'),('Turquoise'),('Tan');
```

## 4) (Optional) Refresh view if needed

If you need `vehicle_url` surfaced in a view, extend the relevant view(s). Current `gyc_v_vechicle_info` does not include vendor fields beyond `company_name`; no change required unless explicitly needed.

## Execution notes

- Run on live `carsafari` DB via cPanel/CLI.
- Verify column not already present before applying.
- No data backfill required (nullable).
