# Live DB Migration Checklist (carsafari)

Planned changes to align live DB with current local schema/use.

## 1) Add vehicle_url to gyc_vendor_info

Purpose: store a vendor-specific URL (used by scraper/config).

```sql
ALTER TABLE gyc_vendor_info
  ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL AFTER maps_url;
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
