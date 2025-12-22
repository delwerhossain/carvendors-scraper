# cPanel Production Deployment - Complete SQL Queries

**Database**: `carsafari` (production live)  
**Vendor**: 432 (systonautosltd)  
**Environment**: MariaDB 10.6.23 on cPanel  
**Purpose**: Safe migration for CarVendors daily refresh with health gates

---

## 📋 Pre-Deployment Checklist

- [ ] Backup live database: `mysqldump -u user -p carsafari > backup_YYYYMMDD.sql`
- [ ] Verify current record count in gyc_vehicle_info: `SELECT COUNT(*) FROM gyc_vehicle_info;`
- [ ] Check vendor 432 current active vehicles: `SELECT COUNT(*) FROM gyc_vehicle_info WHERE vendor_id = 432 AND active_status IN (1,2);`
- [ ] Ensure no ongoing transactions: `SHOW PROCESSLIST;`
- [ ] Schedule during low-traffic window (2:00 AM - 5:00 AM recommended)

---

## ✅ STEP 1: Create scraper_statistics Table (If Missing)

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

## ✅ STEP 2: Verify Core Tables Exist

### Check gyc_vehicle_info Structure
```sql
DESCRIBE gyc_vehicle_info;
```

**Expected key columns**:
- `id` (PK INT auto-increment)
- `vendor_id` (INT, FK to gyc_vendor_info)
- `reg_no` (VARCHAR 255, unique)
- `attr_id` (INT, FK to gyc_vehicle_attribute)
- `selling_price` (INT)
- `mileage` (INT)
- `color` (VARCHAR 100)
- `color_id` (INT)
- `description` (TEXT)
- `active_status` (ENUM 0,1,2,3,4)
- `created_at` (DATETIME)
- `updated_at` (DATETIME)

### Check gyc_vehicle_attribute Structure
```sql
DESCRIBE gyc_vehicle_attribute;
```

**Expected key columns**:
- `id` (PK INT)
- `category_id` (INT)
- `make_id` (INT, FK to gyc_make)
- `model` (VARCHAR 255)
- `year` (INT)
- `fuel_type` (VARCHAR 255)
- `transmission` (VARCHAR 255)
- `body_style` (VARCHAR 50)
- `engine_size` (VARCHAR 255)

### Check gyc_product_images Structure
```sql
DESCRIBE gyc_product_images;
```

**Expected key columns**:
- `id` (PK INT)
- `file_name` (VARCHAR 255, URL reference)
- `vechicle_info_id` (INT, FK to gyc_vehicle_info)
- `serial` (INT, order number)

---

## ✅ STEP 3: Add Missing Columns (If Required)

### Check for vehicle_url column
```sql
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'gyc_vehicle_info' AND COLUMN_NAME = 'vehicle_url';
```

**If missing**, add it:
```sql
ALTER TABLE gyc_vehicle_info ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL 
AFTER description;
```

### Check for data_hash column
```sql
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'gyc_vehicle_info' AND COLUMN_NAME = 'data_hash';
```

**If missing**, add it:
```sql
ALTER TABLE gyc_vehicle_info ADD COLUMN data_hash VARCHAR(32) DEFAULT NULL 
AFTER updated_at;

CREATE INDEX idx_vendor_hash ON gyc_vehicle_info(vendor_id, data_hash);
```

---

## ✅ STEP 4: Verify Vendor 432 Exists

```sql
SELECT id, name, email FROM gyc_vendor_info WHERE id = 432;
```

**Expected result**: One row with vendor details for systonautosltd.

If missing, insert:
```sql
INSERT INTO gyc_vendor_info (id, name, email, active_status, created_at) 
VALUES (432, 'Systonautos Ltd', 'contact@systonautos.co.uk', 1, NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
```

---

## ✅ STEP 5: Create Backup Before First Run

```sql
-- Backup current vendor 432 vehicles
CREATE TABLE gyc_vehicle_info_backup_vendor432 AS 
SELECT * FROM gyc_vehicle_info WHERE vendor_id = 432;

-- Backup related attributes
CREATE TABLE gyc_vehicle_attribute_backup_vendor432 AS 
SELECT gva.* FROM gyc_vehicle_attribute gva
INNER JOIN gyc_vehicle_info gvi ON gvi.attr_id = gva.id
WHERE gvi.vendor_id = 432;

-- Backup related images
CREATE TABLE gyc_product_images_backup_vendor432 AS 
SELECT gpi.* FROM gyc_product_images gpi
INNER JOIN gyc_vehicle_info gvi ON gvi.id = gpi.vechicle_info_id
WHERE gvi.vendor_id = 432;
```

---

## ✅ STEP 6: Document Current Baseline

Run before first daily_refresh.php:
```sql
-- Store baseline counts for inventory ratio validation
INSERT INTO scraper_statistics 
(vendor_id, status, vehicles_found, vehicles_inserted, vehicles_updated, vehicles_skipped)
SELECT 
  432, 
  'baseline', 
  COUNT(*), 
  0, 
  0, 
  0
FROM gyc_vehicle_info 
WHERE vendor_id = 432 AND active_status IN (1, 2);

-- Retrieve baseline for reference
SELECT vehicles_found FROM scraper_statistics 
WHERE vendor_id = 432 AND status = 'baseline' 
ORDER BY run_date DESC LIMIT 1;
```

---

## ⚠️ IMPORTANT: Delete Logic (Phase 0 Cleanup)

The `daily_refresh.php` script performs vendor data cleanup ONLY if both gates pass:

```php
// Gate 1: Success Rate >= 85%
if (($inserted + $updated + $skipped) / $found < 0.85) {
    log("SUCCESS RATE GATE FAILED: " . (($inserted + $updated + $skipped) / $found * 100) . "%");
    return false; // Stop cleanup
}

// Gate 2: Inventory Ratio >= 80%
if ($newCount < ($baselineCount * 0.80)) {
    log("INVENTORY RATIO GATE FAILED: " . ($newCount / $baselineCount * 100) . "%");
    return false; // Stop cleanup
}

// ONLY if both gates pass:
// Delete ALL vendor 432 data (fresh slate)
DELETE FROM gyc_product_images WHERE vechicle_info_id IN 
  (SELECT id FROM gyc_vehicle_info WHERE vendor_id = 432);

DELETE FROM gyc_vehicle_info WHERE vendor_id = 432;
```

**If either gate fails**:
- No data deleted
- Alert email sent
- Manual review required
- Live website remains untouched

---

## 🧪 STEP 7: Test Deployment (Dry Run)

Run without cleanup (dry-run mode - if supported):
```bash
php daily_refresh.php --vendor=432 --dry-run
```

Or run in manual test mode:
```bash
php scripts/scrape-carsafari.php
# Check logs/scraper_YYYY-MM-DD.log for output
# Check data/vehicles.json for parsed vehicles
```

Verify:
- [ ] No errors in logs
- [ ] vehicles.json has >70 records
- [ ] All vehicle data parsed correctly (price, mileage, images, specs)

---

## 🚀 STEP 8: Deploy Scraper Code

Copy files to cPanel:
```bash
# Via SSH/SFTP
scp -r *.php config.php scripts/ src/ user@host:/public_html/carvendors-scraper/
scp -r config.php.example user@host:/public_html/carvendors-scraper/

# Update config.php for production
# database.dbname = carsafari
# scraper.verify_ssl = true (for cPanel)
```

---

## ✅ STEP 9: Configure CRON for Daily Refresh

Generate cPanel CRON command:
```bash
php scripts/setup_cron.php
# Output example:
# 2 2 * * * /usr/bin/php /home/user/public_html/carvendors-scraper/daily_refresh.php
```

Add to cPanel Cron Jobs: **2:00 AM daily** (low traffic window)

---

## 📊 STEP 10: Monitor First Run

After first scheduled run (or manual run), check:

```sql
-- Verify statistics recorded
SELECT * FROM scraper_statistics 
WHERE vendor_id = 432 
ORDER BY run_date DESC LIMIT 5;

-- Count current vehicles (should match expected)
SELECT COUNT(*) as total_vehicles,
       SUM(IF(active_status IN (1,2), 1, 0)) as active_vehicles
FROM gyc_vehicle_info 
WHERE vendor_id = 432;

-- Check gate results
SELECT status, 
       vehicles_found, 
       vehicles_inserted, 
       vehicles_updated, 
       vehicles_skipped,
       success_rate,
       inventory_ratio,
       gates_passed
FROM scraper_statistics 
WHERE vendor_id = 432 
ORDER BY run_date DESC LIMIT 1;
```

Expected after first run:
- vehicles_found: 70-80
- vehicles_inserted: 70-80 (first run, all new)
- success_rate: >85%
- inventory_ratio: 100%
- gates_passed: 1 (true)

---

## 🔄 STEP 11: Subsequent Runs (Daily)

After first run, each daily refresh:

**Phase 0**: Delete ALL vendor 432 data (if gates passed in previous run)
**Phase 1**: Re-scrape from systonautosltd.co.uk
**Phase 2**: Validate health gates
**Phase 3**: Auto-publish new/changed vehicles
**Phase 4**: Record statistics
**Phase 5**: Export JSON snapshot

Expected on normal runs:
- vehicles_found: 75-78 (should be stable)
- vehicles_skipped: 76 (due to hash-based change detection)
- vehicles_updated: 0-2 (only if prices/mileage changed)
- vehicles_inserted: 0 (no new vehicles, all known)
- success_rate: ~97% (skipped + updated + inserted)
- duration: ~1-2 seconds

---

## 🚨 Troubleshooting

### Issue: "SUCCESS RATE GATE FAILED"
- **Cause**: Scraper found 70 vehicles, but only 50 successful (71% < 85%)
- **Action**: Check logs for parse errors, verify website structure unchanged
- **Prevention**: Don't delete data until gates pass manually

### Issue: "INVENTORY RATIO GATE FAILED"
- **Cause**: Only 50 vehicles scraped vs 75 baseline (67% < 80%)
- **Action**: Check website for outages, verify network connectivity
- **Prevention**: Alert will be sent, manual review required

### Issue: Data not updating
- **Cause**: Hash matches (data unchanged) → vehicle skipped
- **Solution**: This is expected behavior! Change detection is working
- **Verify**: Price or mileage should differ to trigger update

### Issue: Foreign key constraint error
- **Cause**: Trying to delete vehicle_info before deleting images
- **Solution**: Code deletes images first (gyc_product_images), then vehicles
- **Check**: Verify FK constraint exists: `CONSTRAINT `fk_vehicle_images`...`

---

## ✨ Success Indicators

After deployment is complete and first run succeeds:

✅ scraper_statistics table populated with first run data  
✅ 70-80 vehicles found from systonautosltd.co.uk  
✅ Success rate >= 85%  
✅ Inventory ratio >= 80%  
✅ Gates passed = 1 (true)  
✅ Subsequent runs take <2 seconds  
✅ Email alerts received for each run  
✅ JSON snapshot exported: `data/vehicles.json`  

**Production Ready**: When all gates pass for 3 consecutive days ✓

