# Live DB Migration Checklist (carsafari)

Planned changes to align live DB with current local schema/use.

---

## 🎨 STEP 0: Seed Color Data (CRITICAL - DO THIS FIRST!)

**Purpose**: Populate `gyc_vehicle_color` table with 23+ canonical colors for accurate color_id mapping.

**Why**: Without this, `color_id` and `manufacturer_color_id` will be **NULL** in scraper results, breaking vehicle listings.

### Via phpMyAdmin (Easiest - Recommended)
```
1. Login to cPanel → phpMyAdmin
2. Select your database (youruser_carsafari)
3. Click "SQL" tab
4. Copy-paste the SQL below
5. Click "Go"
```

```sql
-- Insert 23 canonical colors (IDs 1-23)
INSERT IGNORE INTO gyc_vehicle_color (id, color_name, active_status, created_at) VALUES
(1, 'Beige', 1, NOW()),
(2, 'Black', 1, NOW()),
(3, 'Blue', 1, NOW()),
(4, 'Bronze', 1, NOW()),
(5, 'Brown', 1, NOW()),
(6, 'Burgundy', 1, NOW()),
(7, 'Gold', 1, NOW()),
(8, 'Green', 1, NOW()),
(9, 'Grey', 1, NOW()),
(10, 'Indigo', 1, NOW()),
(11, 'Magenta', 1, NOW()),
(12, 'Mcroon', 1, NOW()),
(13, 'Multicolor', 1, NOW()),
(14, 'Navy', 1, NOW()),
(15, 'Orange', 1, NOW()),
(16, 'Pink', 1, NOW()),
(17, 'Purple', 1, NOW()),
(18, 'Red', 1, NOW()),
(19, 'None', 1, NOW()),
(20, 'White', 1, NOW()),
(21, 'Silver', 1, NOW()),
(22, 'Yellow', 1, NOW()),
(23, 'Lime', 1, NOW());

-- Insert 30+ color variants for better matching accuracy
INSERT IGNORE INTO gyc_vehicle_color (color_name, active_status, created_at) VALUES
('Cream', 1, NOW()),
('Tan', 1, NOW()),
('Sand', 1, NOW()),
('Ecru', 1, NOW()),
('Jet Black', 1, NOW()),
('Pearl Black', 1, NOW()),
('Ebony', 1, NOW()),
('Light Blue', 1, NOW()),
('Sky Blue', 1, NOW()),
('Azure', 1, NOW()),
('Cobalt', 1, NOW()),
('Steel Blue', 1, NOW()),
('Navy Blue', 1, NOW()),
('Copper', 1, NOW()),
('Chocolate', 1, NOW()),
('Mahogany', 1, NOW()),
('Coffee', 1, NOW()),
('Walnut', 1, NOW()),
('Wine', 1, NOW()),
('Claret', 1, NOW()),
('Oxblood', 1, NOW()),
('Olive', 1, NOW()),
('Moss', 1, NOW()),
('Forest Green', 1, NOW()),
('Sage', 1, NOW()),
('Light Grey', 1, NOW()),
('Dark Grey', 1, NOW()),
('Gunmetal', 1, NOW()),
('Slate', 1, NOW()),
('Graphite', 1, NOW()),
('Ash', 1, NOW()),
('Silver Grey', 1, NOW()),
('Pewter', 1, NOW()),
('Fuchsia', 1, NOW()),
('Multi-Color', 1, NOW()),
('Mixed', 1, NOW()),
('Two-Tone', 1, NOW()),
('Dark Red', 1, NOW()),
('Bright Red', 1, NOW()),
('Crimson', 1, NOW()),
('Scarlet', 1, NOW()),
('Ruby', 1, NOW()),
('Cherry', 1, NOW()),
('Fire Red', 1, NOW()),
('Candy Red', 1, NOW()),
('Off White', 1, NOW()),
('Off-White', 1, NOW()),
('Ivory', 1, NOW()),
('Pearl White', 1, NOW()),
('Snow White', 1, NOW()),
('Silver Metallic', 1, NOW()),
('Polished Silver', 1, NOW()),
('Light Yellow', 1, NOW()),
('Bright Yellow', 1, NOW()),
('Golden Yellow', 1, NOW()),
('Lemon', 1, NOW()),
('Banana', 1, NOW()),
('Lime Green', 1, NOW()),
('Neon Green', 1, NOW()),
('Neon Yellow', 1, NOW());

-- Verify: Expected 52+ colors total
SELECT COUNT(*) as total_colors FROM gyc_vehicle_color;
```

### Via SSH (Advanced)
```bash
# Connect to server
ssh user@yourdomain.com
cd ~/public_html/carvendors-scraper

# Download color seed (if using git)
git pull origin main

# Execute color seed on live database
mysql -u youruser_dbuser -p youruser_carsafari < sql/COLOR_SEED_DATA.sql
```

### Verify Colors Were Seeded
```sql
-- In phpMyAdmin SQL tab, run:
SELECT COUNT(*) as total_colors FROM gyc_vehicle_color;
-- Expected: 52+ colors (23 canonical + variants)

SELECT id, color_name FROM gyc_vehicle_color ORDER BY id LIMIT 25;
-- Expected: ID 1-23 with color names like Black, Blue, Red, etc.
```

---

## 1) Add vehicle_url to gyc_vendor_info

Purpose: store a vendor-specific URL (used by scraper/config).

```sql
ALTER TABLE gyc_vendor_info
  ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL AFTER maps_url;
```

---

## 2) Create statistics/support tables (if missing)

Needed by the scraper's `StatisticsManager` and logging.

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

---

## 3) (Optional) Refresh view if needed

If you need `vehicle_url` surfaced in a view, extend the relevant view(s). Current `gyc_v_vechicle_info` does not include vendor fields beyond `company_name`; no change required unless explicitly needed.

---

## Execution Checklist

- [ ] **STEP 0: Seed colors** (essential!)
- [ ] Step 1: Add vehicle_url to gyc_vendor_info
- [ ] Step 2: Create statistics/logs tables
- [ ] Verify: Run sample query to check colors exist
- [ ] Verify: Run scraper and check color_id is populated (not NULL)
- [ ] Verify: Check data/vehicles.json has color_id fields filled

## Verification Queries

```sql
-- Check colors were seeded
SELECT COUNT(*) as total_colors FROM gyc_vehicle_color;

-- Check color ID mapping works
SELECT id, color_name FROM gyc_vehicle_color WHERE color_name IN ('Red', 'Black', 'Blue', 'White');
-- Expected: 4 rows with proper IDs

-- After running scraper, check color_id is populated
SELECT COUNT(*) as with_color_id FROM gyc_vehicle_info 
WHERE vendor_id=432 AND color_id IS NOT NULL;
-- Expected: 68 (or your vehicle count, NOT 0)

-- Check for any remaining NULL color_id (indicates mapping issue)
SELECT COUNT(*) as null_color_id FROM gyc_vehicle_info 
WHERE vendor_id=432 AND color_id IS NULL;
-- Expected: 0
```

---

## Notes

- Colors are seeded with IDs 1-23 (canonical) + variants for better matching
- The scraper uses case-insensitive mapping: "Red" matches ID 18, "red" matches ID 18, etc.
- If colors already exist, use `INSERT IGNORE` to avoid duplicates
- Color variants help with fuzzy matching when exact matches fail
- Test locally first, then apply to live!
